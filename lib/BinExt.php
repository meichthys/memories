<?php

namespace OCA\Memories;

class BinExt
{
    public const EXIFTOOL_VER = '12.58';
    public const GOVOD_VER = '0.0.34';

    /** Test configured exiftool binary */
    public static function testExiftool(): bool
    {
        $cmd = implode(' ', array_merge(self::getExiftool(), ['-ver']));
        $out = shell_exec($cmd);
        if (!$out) {
            throw new \Exception('failed to run exiftool');
        }

        $version = trim($out);
        $target = self::EXIFTOOL_VER;
        if (!version_compare($version, $target, '=')) {
            throw new \Exception("version does not match {$version} <==> {$target}");
        }

        return true;
    }

    /** Get path to exiftool binary for proc_open */
    public static function getExiftool(): array
    {
        if (Util::getSystemConfig('memories.exiftool_no_local')) {
            return ['perl', __DIR__.'/../exiftool-bin/exiftool/exiftool'];
        }

        return [Util::getSystemConfig('memories.exiftool')];
    }

    /** Detect the exiftool binary to use */
    public static function detectExiftool()
    {
        if (!empty($path = Util::getSystemConfig('memories.exiftool'))) {
            if (file_exists($path) && !is_executable($path)) {
                @chmod($path, 0755);
            }

            return $path;
        }

        if (Util::getSystemConfig('memories.exiftool_no_local')) {
            return implode(' ', self::getExiftool());
        }

        // Detect architecture
        $arch = \OCA\Memories\Util::getArch();
        $libc = \OCA\Memories\Util::getLibc();

        // Get static binary if available
        if ($arch && $libc) {
            // get target file path
            $path = realpath(__DIR__."/../exiftool-bin/exiftool-{$arch}-{$libc}");

            // Set config
            Util::setSystemConfig('memories.exiftool', $path);

            // make sure it is executable
            if (file_exists($path)) {
                if (!is_executable($path)) {
                    @chmod($path, 0755);
                }

                return $path;
            }
        }

        Util::setSystemConfig('memories.exiftool_no_local', true);

        return false;
    }

    /**
     * Get the upstream URL for a video.
     */
    public static function getGoVodUrl(string $client, string $path, string $profile): string
    {
        $path = rawurlencode($path);

        $bind = Util::getSystemConfig('memories.vod.bind');
        $connect = Util::getSystemConfig('memories.vod.connect', $bind);

        return "http://{$connect}/{$client}{$path}/{$profile}";
    }

    public static function getGoVodConfig($local = false)
    {
        // Get config from system values
        $env = [
            'vaapi' => Util::getSystemConfig('memories.vod.vaapi'),
            'vaapiLowPower' => Util::getSystemConfig('memories.vod.vaapi.low_power'),

            'nvenc' => Util::getSystemConfig('memories.vod.nvenc', false),
            'nvencTemporalAQ' => Util::getSystemConfig('memories.vod.nvenc.temporal_aq'),
            'nvencScale' => Util::getSystemConfig('memories.vod.nvenc.scale'),
        ];

        if (!$local) {
            return $env;
        }

        // Get temp directory
        $tmpPath = Util::getSystemConfig('memories.vod.tempdir', sys_get_temp_dir().'/go-vod/');

        // Make sure path ends with slash
        if ('/' !== substr($tmpPath, -1)) {
            $tmpPath .= '/';
        }

        // Add instance ID to path
        $tmpPath .= Util::getSystemConfig('instanceid', 'default');

        return array_merge($env, [
            'bind' => Util::getSystemConfig('memories.vod.bind'),
            'ffmpeg' => Util::getSystemConfig('memories.vod.ffmpeg'),
            'ffprobe' => Util::getSystemConfig('memories.vod.ffprobe'),
            'tempdir' => $tmpPath,
        ]);
    }

    /**
     * If local, restart the go-vod instance.
     * If external, configure the go-vod instance.
     */
    public static function startGoVod()
    {
        // Check if external
        if (Util::getSystemConfig('memories.vod.external')) {
            self::configureGoVod();

            return;
        }

        // Get transcoder path
        $transcoder = Util::getSystemConfig('memories.vod.path');
        if (empty($transcoder)) {
            throw new \Exception('Transcoder not configured');
        }

        // Make sure transcoder exists
        if (!file_exists($transcoder)) {
            throw new \Exception("Transcoder not found; ({$transcoder})");
        }

        // Make transcoder executable
        if (!is_executable($transcoder)) {
            @chmod($transcoder, 0755);
            if (!is_executable($transcoder)) {
                throw new \Exception("Transcoder not executable (chmod 755 {$transcoder})");
            }
        }

        // Get local config
        $env = self::getGoVodConfig(true);
        $tmpPath = $env['tempdir'];

        // (Re-)create temp dir
        shell_exec("rm -rf '{$tmpPath}' && mkdir -p '{$tmpPath}' && chmod 755 '{$tmpPath}'");

        // Check temp directory exists
        if (!is_dir($tmpPath)) {
            throw new \Exception("Temp directory could not be created ({$tmpPath})");
        }

        // Check temp directory is writable
        if (!is_writable($tmpPath)) {
            throw new \Exception("Temp directory is not writable ({$tmpPath})");
        }

        // Write config to file
        $logFile = $tmpPath.'.log';
        $configFile = $tmpPath.'.json';
        file_put_contents($configFile, json_encode($env, JSON_PRETTY_PRINT));

        // Kill the transcoder in case it's running
        \OCA\Memories\Util::pkill($transcoder);

        // Start transcoder
        shell_exec("nohup {$transcoder} {$configFile} >> '{$logFile}' 2>&1 & > /dev/null");

        // wait for 500ms
        usleep(500000);

        return $logFile;
    }

    /**
     * Test go-vod and (re)-start if it is not external.
     */
    public static function testStartGoVod(): bool
    {
        try {
            return self::testGoVod();
        } catch (\Exception $e) {
            // silently try to restart
        }

        // Attempt to (re)start go-vod
        // If it is external, this only attempts to reconfigure
        self::startGoVod();

        // Test again
        return self::testGoVod();
    }

    /** Test the go-vod instance that is running */
    public static function testGoVod(): bool
    {
        // TODO: check data mount; ignoring the result of the file for now
        $testfile = realpath(__DIR__.'/../exiftest.jpg');

        // Make request
        $url = self::getGoVodUrl('test', $testfile, 'test');

        try {
            $client = new \GuzzleHttp\Client();
            $res = $client->request('GET', $url);
        } catch (\Exception $e) {
            throw new \Exception('failed to connect to go-vod: '.$e->getMessage());
        }

        // Parse body
        $json = json_decode($res->getBody(), true);
        if (!$json) {
            throw new \Exception('failed to parse go-vod response');
        }

        // Check version
        $version = $json['version'];
        $target = self::GOVOD_VER;
        if (!version_compare($version, $target, '=')) {
            throw new \Exception("version does not match {$version} <==> {$target}");
        }

        return true;
    }

    /** POST a new configuration to go-vod */
    public static function configureGoVod()
    {
        // Get config
        $config = self::getGoVodConfig();

        // Make request
        $url = self::getGoVodUrl('config', '/config', 'config');

        try {
            $client = new \GuzzleHttp\Client();
            $client->request('POST', $url, [
                'json' => $config,
            ]);
        } catch (\Exception $e) {
            throw new \Exception('failed to connect to go-vod: '.$e->getMessage());
        }

        return true;
    }

    /** Detect the go-vod binary to use */
    public static function detectGoVod()
    {
        $goVodPath = Util::getSystemConfig('memories.vod.path');

        if (empty($goVodPath) || !file_exists($goVodPath)) {
            // Detect architecture
            $arch = \OCA\Memories\Util::getArch();
            $path = __DIR__."/../exiftool-bin/go-vod-{$arch}";
            $goVodPath = realpath($path);

            if (!$goVodPath) {
                return false;
            }

            // Set config
            Util::setSystemConfig('memories.vod.path', $goVodPath);

            // Make executable
            if (!is_executable($goVodPath)) {
                @chmod($goVodPath, 0755);
            }
        }

        return $goVodPath;
    }

    public static function detectFFmpeg()
    {
        $ffmpegPath = Util::getSystemConfig('memories.vod.ffmpeg');
        $ffprobePath = Util::getSystemConfig('memories.vod.ffprobe');

        if (empty($ffmpegPath) || !file_exists($ffmpegPath) || empty($ffprobePath) || !file_exists($ffprobePath)) {
            // Use PATH
            $ffmpegPath = shell_exec('which ffmpeg');
            $ffprobePath = shell_exec('which ffprobe');
            if (!$ffmpegPath || !$ffprobePath) {
                return false;
            }

            // Trim
            $ffmpegPath = trim($ffmpegPath);
            $ffprobePath = trim($ffprobePath);

            // Set config
            Util::setSystemConfig('memories.vod.ffmpeg', $ffmpegPath);
            Util::setSystemConfig('memories.vod.ffprobe', $ffprobePath);
        }

        // Check if executable
        if (!is_executable($ffmpegPath) || !is_executable($ffprobePath)) {
            return false;
        }

        return $ffmpegPath;
    }
}
