<?php

class KokenVimeo extends KokenPlugin {

	function __construct()
	{
		$this->database_fields = array(
			'content' => array(
				'koken_vimeo' => array(
					'type' => 'TINYINT',
					'constraint' => 1,
					'default' => 0
				),
				'koken_vimeo_id' => array(
					'type' => 'INT',
					'constraint' => 11,
					'unsigned' => true,
					'null' => true,
				)
			)
		);

		$this->register_filter('api.content', 'filter');
		$this->register_filter('site.templates', 'templates_filter');

		$this->register_hook('content.listing', 'listing');
		$this->register_site_script(dirname(__FILE__) . '/plugin.js');
	}

	function templates_filter($data)
	{
		$contents = false;
		foreach($data as $template)
		{
			if ($template['path'] === 'contents')
			{
				$contents = $template;
			}
		}

		if ($contents)
		{
			$contents['path'] = 'vimeo';
			$contents['info']['template'] = 'contents';
			$contents['info']['name'] = 'Vimeo';
			$contents['info']['icon'] = 'vimeo';
			$contents['info']['description'] = 'Displays all imported Vimeo videos';
			$contents['info']['filters'] = array('koken_vimeo=1');
			$data[] = $contents;
		}

		return $data;
	}

	function confirm_setup()
	{
		$username = trim($this->data->username);
		if (!empty($username))
		{
			$user = Shutter::get_json_api('http://vimeo.com/api/v2/' . $username . '/info.json');
			if ($user) {
				return true;
			} else {
				return 'Vimeo user: ' . $username . ' not found.';
			}
		}
		return true;
	}

	function listing($content, $options)
	{
		if (isset($options['koken_vimeo']))
		{
			$content->where('koken_vimeo', (int) $options['koken_vimeo']);
		}
	}

	function candidates()
	{
		if ($this->data->username)
		{
			$c = new Content;
			$existing = array();
			$existing_db = $c->where('koken_vimeo', 1)->get_iterated();
			foreach($existing_db as $content)
			{
				$existing[] = $content->koken_vimeo_id;
			}

			$user = Shutter::get_json_api('http://vimeo.com/api/v2/' . $this->data->username . '/info.json');
			$total = $user->total_videos_uploaded;
			$pages = min(3, ceil($total / 20));

			$vids = array();

			for ($i=1; $i <= max(1, $pages - 1); $i++) {
				$data = Shutter::get_json_api('http://vimeo.com/api/v2/' . $this->data->username . '/videos.json?page=' . $i);
				foreach($data as $video)
				{
					if (!in_array($video->id, $existing) && $video->embed_privacy !== 'nowhere')
					{
						$vids[] = $video;
					}
				}
			}

			return array(
				'account' => $user,
				'videos' => $vids,
			);
		}
	}

	function filter($data)
	{
		if ($data['koken_vimeo'] == 1)
		{
			$html = $data['html'];
			preg_match('/src="([^"]+)"/', $html, $matches);
			$src = $matches[1];
			if (strpos($src, '?') === false)
			{
				$params = array();
				foreach($this->data as $key => $val)
				{
					$params[] = "$key=" . ($key === 'color' ? str_replace('#', '', $val) : ( $val ? 1 : 0 ));
				}
				$params[] = 'api=1';
				$params[] = 'player_id=koken_vimeo_' . $data['id'];
				$src .= '?' . join('&', $params);
				$data['html'] = preg_replace('/src="[^"]+"/', 'src="' . $src . '"', $html);
			}
			$data['koken_vimeo'] = true;
			$data['filename'] = "Vimeo";

			$data['html'] = html_entity_decode($data['html']);

			$data['console_html'] = str_replace('autoplay=1', 'autoplay=0', $data['html']);

			$data['pulse_html'] = str_replace('<iframe', '<iframe id="koken_vimeo_' . $data['id'] . '"', $data['html']);

			if (strpos($data['pulse_html'], 'autoplay=1') !== false)
			{
				$data['pulse_html'] = str_replace('autoplay=1', 'autoplay=0', $data['pulse_html']);
				$data['pulse_html'] = str_replace('<iframe', '<iframe data-autoplay', $data['pulse_html']);
			}
		}
		else
		{
			$data['koken_vimeo'] = false;
		}

		unset($data['koken_vimeo_id']);

		return $data;
	}

	private function create_from_id($id, $oembed_data = false)
	{
		$video = Shutter::get_json_api('http://vimeo.com/api/v2/video/' . $id . '.json');
		$video = $video[0];
		if (!$oembed_data)
		{
			$oembed_data = Shutter::get_oembed('https://vimeo.com/api/oembed.json?url=http://vimeo.com/' . $id . '&width=' . $video->width);
		}
		$c = new Content;
		$check = $c->where('koken_vimeo_id', $video->id)->get();
		if (!$check->exists())
		{
			$c->filename = $video->url;
			$c->file_modified_on = time();
			$c->filesize = 0;
			$c->file_type = 1;
			$c->koken_vimeo = 1;
			$c->source = 'Vimeo';
			$c->source_url = $video->url;
			$c->koken_vimeo_id = $video->id;
			$c->html = $oembed_data['html'];
			$c->visibility = $_POST['visibility'];

			date_default_timezone_set('America/New_York');
			$ts = strtotime($video->upload_date);
			date_default_timezone_set('UTC');
			$c->uploaded_on = $c->modified_on = $ts;

			$c->captured_on = strtotime($video->upload_date);

			$s = new Setting;
			$s->where('name', 'site_timezone')->get();

			$tz = new DateTimeZone($s->value);
			$offset_user = $tz->getOffset( new DateTime('now', new DateTimeZone('UTC')) );

			$tz = new DateTimeZone('America/New_York');
			$offset_est = $tz->getOffset( new DateTime('now', new DateTimeZone('UTC')) );

			$c->captured_on += $offset_user - $offset_est;

			$tags = array();

			if ($this->data->assign_tag)
			{
				$tags[] = 'vimeo';
			}

			if ($this->data->tags)
			{
				$tags = array_merge($tags, explode(',', $video->tags));
			}

			list($c->internal_id,) = $c->generate_internal_id();

			$c->_set_paths();
			$lg_preview = basename($oembed_data['thumbnail_url']);
			$c->lg_preview = "$lg_preview:50:50";
			$preview_path = $c->path_to_original() . '_previews' . DIRECTORY_SEPARATOR . $lg_preview;
			make_child_dir(dirname($preview_path));
			$this->download_file($oembed_data['thumbnail_url'], $preview_path);

			if (!file_exists($preview_path))
			{
				$this->download_file($video->thumbnail_large, $preview_path);
			}

			foreach(array('width', 'height', 'duration', 'title', 'description') as $field)
			{
				$koken_field = $field === 'description' ? 'caption' : $field;
				$c->{$koken_field} = $video->{$field};
			}

			return $c->create(array('tags' => $tags));
		}
		else
		{
			return false;
		}
	}

	function create_api()
	{
		if (!isset($_POST['ids']))
		{
			return array('error' => 400, 'message' => 'Vimeo IDs not specified.');
		}

		$ids = explode(',', $_POST['ids']);
		$added = array();

		foreach($ids as $id)
		{
			$koken_id = $this->create_from_id($id);
			if ($koken_id)
			{
				$added[] = $koken_id;
			}
		}

		return $added;
	}

	function create()
	{
		if (!isset($_POST['url']))
		{
			return array('error' => 400, 'message' => 'Vimeo URL not specified.');
		}

		$info = Shutter::get_oembed('https://vimeo.com/api/oembed.json?url=' . urldecode($_POST['url']));

		if ($info)
		{
			$koken_id = $this->create_from_id($info['video_id'], $info);
			if ($koken_id)
			{
				return array('koken:redirect' => '/content/' . $koken_id);
			}
			else
			{
				return array('error' => 500, 'message' => 'That Vimeo video has already been added to your library.');
			}
		}
		else
		{
			return array('error' => 404, 'message' => 'Vimeo video not found at that URL.');
		}
	}
}