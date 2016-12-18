<?php

$conf = $GLOBALS['CONFIG']['DOWNLOAD'];

if(isset($conf['REQUIRE_USER']))
{
	if(get_current_user() != $conf['require-user'])
	{
		stderr(
			'Not downloading files for user %s, run this script as user %s',
			get_current_user(),
			$conf['require-user']
		);
		exit(2);
	}
}

$conferences = Conferences::getConferences();

if(isset($conf['MAX_CONFERENCE_AGE']))
{
	$months = intval($conf['MAX_CONFERENCE_AGE']);
	$conferencesAfter = new DateTime();
	$conferencesAfter->sub(new DateInterval('P'.$months.'D'));

	stdout('Skipping Conferences before %s', $conferencesAfter->format('Y-m-d'));
	$conferences = array_filter($conferences, function($conference) use ($conferencesAfter) {
		if($conference->isOpen())
		{
			stdout(
				'  %s: %s',
				'---open---',
				$conference->getSlug()
			);

			return true;
		}

		$isBefore = $conference->endsAt() < $conferencesAfter;

		if($isBefore) {
			stdout(
				'  %s: %s',
				$conference->endsAt()->format('Y-m-d'),
				$conference->getSlug()
			);
		}

		return !$isBefore;
	});
}

stdout('');
foreach ($conferences as $conference)
{
	stdout('== %s ==', $conference->getSlug());

	$relive = $conference->getRelive();
	if($relive->isEnabled())
	{
		download(
			'relive-json',
			$conference,
			$relive->getJsonUrl(),
			$relive->getJsonCache()
		);
	}

	$schedule = $conference->getSchedule();
	if($schedule->isEnabled())
	{
		download(
			'schedule-xml',
			$conference,
			$schedule->getScheduleUrl(),
			$schedule->getScheduleCache()
		);
	}

	foreach($conference->getExtraFiles() as $filename => $url)
	{
		download(
			'extra-file',
			$conference,
			$url,
			get_file_cache($conference, $filename)
		);
	}
}




function get_file_cache($conference, $filename)
{
	return joinpath([$GLOBALS['BASEDIR'], 'configs/conferences', $conference->getSlug(), $filename]);
}

function download($what, $conference, $url, $cache)
{
	$info = parse_url($url);
	if(!isset($info['scheme']) || !isset($info['host']))
	{
		stderr(
			'  !! %s url for conference %s does look like an old-style path: "%s". please update to a full http/https url',
			$what,
			$conference->getSlug(),
			$url
		);
		return false;
	}

	stdout(
		'  downloading %s from %s to %s',
		$what,
		$url,
		$cache
	);
	if(!do_download($url, $cache))
	{
		stderr(
			'  !! download %s for conference %s from %s to %s failed miserably !!',
			$what,
			$conference->getSlug(),
			$url,
			$cache
		);
	}
	return true;
}

function do_download($url, $cache)
{
	$handle = curl_init($url);
	curl_setopt_array($handle, [
		CURLOPT_FOLLOWLOCATION  => true,
		CURLOPT_MAXREDIRS       => 10,
		CURLOPT_RETURNTRANSFER  => true,
		CURLOPT_SSL_VERIFYPEER  => false, /* accept all certificates, even self-signed */
		CURLOPT_SSL_VERIFYHOST  => 2,     /* verify hostname is in cert */
		CURLOPT_CONNECTTIMEOUT  => 3,     /* connect-timeout in seconds */
		CURLOPT_TIMEOUT         => 5,     /* transfer timeout im seconds */
		CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		CURLOPT_REFERER         => 'https://streaming.media.ccc.de/',
		CURLOPT_USERAGENT       => '@c3voc Streaming-Website Downloader-Cronjob, Contact voc AT c3voc DOT de in case of problems. Might the Winkekatze be with you',
	]);

	$return = curl_exec($handle);
	$info = curl_getinfo($handle);
	curl_close($handle);

	if($info['http_code'] != 200)
		return false;

	$tempfile = tempnam(dirname($cache), 'dl-');
	if(false === file_put_contents($tempfile, $return))
		return false;

	chmod($tempfile, 0644);
	rename($tempfile, $cache);

	return true;
}