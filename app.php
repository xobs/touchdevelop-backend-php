<?php

require_once 'System.php';

$log_dir = '/tmp/log/';
$build_dir = $log_dir . 'build/';

header("access-control-allow-origin: *");
header("access-control-allow-credentials: true");
header("access-control-expose-headers: ErrorMessage");

function plog($str) {
    $filp = fopen("/tmp/log/php.log", "a");
    fwrite($filp, "[" . microtime() . "]: ");
    fwrite($filp, trim($str));
    fwrite($filp, "\n");
    fclose($filp);
}

function makeRequest()
{
    $content = file_get_contents('php://input');
    $data = json_decode($content, true);
    return $data;
}

function do_extension_compile() {

    global $log_dir;
    global $build_dir;

    $name_base = $log_dir . time() . "-" . rand() . '-';

    plog("Getting contents of stdin");
    // The data is gzipped, json-encoded, base-64 encoded, and json-reencoded.
    $contentjbjz = file_get_contents('php://input');
    plog("Creating output file");
    file_put_contents($name_base . '.json.base64.json.gz', $contentjbjz);

    // php doesn't like the gzip header
    $contentjbj = gzinflate(substr($contentjbjz, 10, -8));
    file_put_contents($name_base . '.json.base64.json', $contentjbj);

    $contentjb = json_decode($contentjbj, true);
    file_put_contents($name_base . '.json.base64', $contentjb);

    $contentj = base64_decode($contentjb["data"]);
    file_put_contents($name_base . '.json', $contentj);

    $id = hash("sha256", $contentj);
    file_put_contents($log_dir . $id . '.json', $contentj);

    $content = json_decode($contentj, true);

    if (!file_exists($build_dir)) {
        if (!mkdir($build_dir, 0775, true)) {
            echo "Cannot make build dir";
            exit(0);
        }
    }

    if (!file_exists($build_dir . $id)) {
        if (!mkdir($build_dir . $id, 0775, true)) {
            echo "Cannot make build/$id dir";
            exit(0);
        }
    }

    exec("cp -a /pxt-ltc-core/* " . $build_dir . $id, $output);
    foreach ($content["replaceFiles"] as $name => $value) {
        if (!file_exists(dirname($build_dir . $id . $name)))
            mkdir(dirname($build_dir . $id . $name), 0777, 1);
        file_put_contents($build_dir . $id . $name, $value);
    }

    exec("cd " . $build_dir . $id . "; make 2>&1", $make_output, $retval);

    header("content-type: application/json");

    // If compilation failed, save the output for the extension call.
    if ($retval) {
        file_put_contents($build_dir . $id . '/compile-error.txt',
                          implode("\n", $make_output));
    }

    echo json_encode(
        array(
            'ready' => true,
            'started' => true,
            'hex' => 'https://pxt-compile.xobs.io/compile/' . $id . '.hex'
        )
    );
    exit(0);
}

$request = makeRequest();
$config = array(
    "archive_dir" => "compiler_archives"
    ,"temp_dir" => "/tmp"
    ,"arduino_cores_dir" => "/opt/codebender/codebender-arduino-core-files"
    ,"external_core_files" => "/opt/codebender/external-core-files"
    ,"objdir" => "codebender_object_files"
    ,"logdir" => "codebender_log"
    ,"archive_dir" => "compiler_archives"
    ,"object_directory" => "/tmp/codebender_object_files"
);

// Turn off all error reporting.  Disable this when debugging.
error_reporting(0);

$operation = explode("/", $_SERVER['DOCUMENT_URI']);
switch ($operation[1]) {
    case "compile":
        $id = explode(".", $operation[2])[0];
        $ext = explode(".", $operation[2])[1];

        $hex_file = $build_dir . $id . '/pxt-ltc-core.hex';
        
        if ($ext == "json") {
            header("content-type: application/json");

            if (file_exists($build_dir . $id . '/compile-error.txt')) {
                echo json_encode(
                    array(
                        'success' => false,
                        'hexurl' => "",
                        'mbedresponse' => array( 
                            'result' => array(
                                'exception' => file_get_contents($build_dir . $id . '/compile-error.txt')
                            )
                        )
                    )
                );
                exit(0);
            }

            if (!file_exists($hex_file)) {
                header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found", true, 404);
                echo json_encode(
                    array(
                        'message' => 'compilation ' . $id . ' not found'
                    )
                );
                exit(0);
            }

            echo json_encode(
                array(
                    'success' => true,
                    'hexurl' => 'https://pxt-compile.xobs.io/compile/' . $id . '.hex'
                )
            );
            exit(0);
        }

        if (!file_exists($hex_file)) {
            header("content-type: application/json");
            header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found", true, 404);
            echo json_encode(
                array(
                    'message' => 'compilation ' . $id . ' not found'
                )
            );
            exit(0);
        }

        $hex_contents = file_get_contents($hex_file);
        echo $hex_contents;
        exit(0);
        break;

    case "api":
        switch ($operation[2]) {
            case "clientconfig":
                header("content-type: application/json");
                echo json_encode(
                    array(
                        'primaryCdnUrl' => 'https://pxt-compile.xobs.io'
                    )
                );
                exit(0);

            case 'compile':
                switch ($operation[3]) {
                    case "extension":
                        do_extension_compile();
                        exit(0);
                        break;
                    default:
                        plog("Unrecognized api/compile operation: $operation[3]");
                        break;
                }
                break;
            default:
                plog("Unrecognized api operation: $operation[2]");
                break;
        }
	break;
    default:
        plog("Unrecognized operation $operation[1]");
        break;
}
echo "Unhandled operation";

?>
