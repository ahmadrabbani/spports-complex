<?php
// Shared PHP 5.5-compatible configuration resolution for both entry points.
function comparison_config_path()
{
    $local = __DIR__.'/config.php';
    if (is_file($local)) { return $local; }
    $external = getenv('EBIZ_COMPARE_CONFIG');
    return $external ? $external : $local;
}
function comparison_load_config($path)
{
    // Isolate configuration-file variables from the calling entry point.
    return require $path;
}
