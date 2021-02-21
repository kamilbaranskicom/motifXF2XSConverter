<?php
//
$programName = 'Motif XF2XS converter';
$programVersion = '0.01';
$programAuthor = 'kamilbaranski.com';
$programAuthorWebsite = 'http://kamilbaranski.com/';
$programDescription = 'this program converts Yamaha Motif XF files (SysEx or John Melas strings) to Yamaha Motif XS files.';
//
//
// todo:
// - drag&drop etc
// - finish the xsFallbackWaveforms.txt
// - documentation
// - try to convert effects (support more SysExes)
// - handle USR4
// - handle Melas headers (allxf files, etc)
// - handle basic errors


convertFile('5pad.voi');

// convertFile('XFfactory sysex.allxf');


function convertFile($filename) {
    global $output;

    init();
    if (!$voice = file_get_contents($filename)) {
        notice('ERROR loading file.');
        exit;
    }

    $sysExes = explode(chr(0xF0), $voice);      // skipping the part before first SysEx. 
    unset($sysExes[0]);                         // there might be Melas software's header but we don't need it for now
    // we drop the initial 0xF0 (with explode), so let's add it.
    $sysExes = array_map(function ($sysEx) {
        return chr(0xF0) . $sysEx;
    }, $sysExes);

    $sysExes = array_map('convertSysex', $sysExes);
    $sysExes = array_map('updateChecksum', $sysExes);
    $convertedVoice = join($sysExes);
    $destinationFilename = $filename . '_XS.syx';
    if (($output != 'HTML') && ($output != 'TXT')) {
        sendFileToUser($destinationFilename, $convertedVoice);
    }

    // notice('original length: ' . strlen($voice) . '; converted length: ' . strlen($convertedVoice) . '.');
    // array_walk($sysExes, function ($sysEx) { notice('SYSEX: '.parseNiceSysEx($sysEx)); });

    if (file_put_contents($destinationFilename, $convertedVoice)) {
        notice('File saved as ' . $destinationFilename);
    } else {
        notice('Problems saving file.');
    }

    outit();
}

function sendFileToUser($filename, $data) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($data));
    ob_clean();
    flush();
    echo $data;
}

function convertSysex($sysEx) {
    global $banksNames, $bank, $number, $bankName;

    if (ord($sysEx[4]) != 0x12) {
        // this is not Motif XF sysex!
        notice('! *** This is not XF sysex *** ! (This might cause unpredictable effects.) [' . parseNiceSysEx($sysEx) . ']');
    }

    // the simplest thing: change Model ID (5th byte) from 0x12 (XF) to 0x03 (XS).
    $sysEx[4] = chr(0x03);

    if (compare($sysEx, 'F04300 7F03 0000 0E')) {             // 02 66 f7
        // here comes the bulk voice location. [XF data list page 129]
        $bank = ord($sysEx[8]);
        $number = ord($sysEx[9]);
        $bankName = $banksNames[$bank];
    }

    if (compare($sysEx, 'F04300 7F03 0052400000')) {
        // here comes the voice name!
        $voiceName = substr($sysEx, 10, 20);
        $voiceName = str_pad(rtrim($voiceName), 20);
        notice('');
        notice('# Found voice ' . $voiceName . ' (' . $bankName . ': ' . ($number + 1) . ')');
    }

    if (compare($sysEx, 'F04300 7F03 005F41')) {
        // here comes the part information!
        // the most important thing - convert the waveNumber from XF to XS.
        $waveNumberXF = convertMsbLsbToNumber(ord($sysEx[13]), ord($sysEx[14]));

        $waveNumberXS = convertWaveNumberXFtoXS($waveNumberXF);

        $sysEx[13] = chr(getMsbFromNumber($waveNumberXS));
        $sysEx[14] = chr(getLsbFromNumber($waveNumberXS));

        /*
        // debug
        $waveNumberDebug = '; waveNumber=' . $waveNumberXF;
        $waveNumberDebug .= '; XS=' . $waveNumberXS;
        notice(parseNiceSysEx($sysEx) . ' [' . strlen($sysEx) . ' bytes' . $waveNumberDebug . ']');
        */
    }

    $sysEx = updateChecksum($sysEx);
    return $sysEx;
}

function compare($binarySysExHaystack, $hexStringNeedle) {              // allows for needle='f0 43 00 7f' as well as 'F043007F'
    $binaryNeedle = hex2bin(str_replace(' ', '', $hexStringNeedle));              // replace hex2bin(...) with pack("H*", ...) for PHP <5.4
    return (substr($binarySysExHaystack, 0, strlen($binaryNeedle)) == $binaryNeedle);
}

function updateChecksum($sysEx) {
    $sysEx[strlen($sysEx) - 2] = chr(calculateChecksum($sysEx));
    return $sysEx;
}

function calculateChecksum($sysEx) {
    $checksum = 0;
    for ($i = 5; $i < strlen($sysEx) - 2; $i++) {
        $checksum = $checksum + ord($sysEx[$i]);
    }
    $checksum = (128 - ($checksum % 128)) % 128;
    return $checksum;
}

function convertMsbLsbToNumber($msb, $lsb) {
    return ($msb << 7) + $lsb;
}

function getMsbFromNumber($number) {
    return ($number >> 7);
}

function getLsbFromNumber($number) {
    return ($number & 0x7F);
}

function init() {
    global $xsWaveforms, $xfWaveforms, $xsWaveformsFlipped, $xfWaveformsFlipped, $xsFallbackWaveformsFlipped, $banksNames, $output, $startMicrotime;

    $startMicrotime = microtime(true);

    $output = (isset($_GET['output'])) ? strtoupper($_GET['output']) : 'HTML';

    // in html and txt mode the program outputs diagnostic messages
    // all other output setting causes sending syx (sysex) file straight to brower.
    if ($output == 'HTML') {
        initHTMLOutput();
    } else if ($output == 'TXT') {
        initTXTOutput();
    }

    $xsWaveforms = loadWaveformsList('xsWaveformsList.txt', '/([0-9]*) (.*) .*/');
    $xfWaveforms = loadWaveformsList('xfWaveformsList.txt', '/([0-9]*) (.*) .* .*/');
    $xsFallbackWaveformsFlipped = loadWaveformsList('xsFallbackWaveformsList.txt', '/([0-9]*) (.*)/', true);
    $xsWaveformsFlipped = array_flip($xsWaveforms);
    $xsWaveformsFlipped = array_merge($xsWaveformsFlipped, $xsFallbackWaveformsFlipped);
    $xfWaveformsFlipped = array_flip($xfWaveforms);

    $banksNames = array(
        0x00 => 'Voice PRE 1',          // (nn = 0 .. 127)
        0x01 => 'Voice PRE 2',          // ...
        0x02 => 'Voice PRE 3',
        0x03 => 'Voice PRE 4',
        0x04 => 'Voice PRE 5',
        0x05 => 'Voice PRE 6',
        0x06 => 'Voice PRE 7',
        0x07 => 'Voice PRE 8',
        0x09 => 'Voice GM',
        0x0A => 'Voice USER 1',
        0x0B => 'Voice USER 2',
        0x0C => 'Voice USER 3',
        0x0D => 'Voice USER 4',
        0x0F => 'Voice Edit Buffer',    // (nn = 0)
        0x20 => 'Drum Voice PRE',       // (nn = 0 – 63)
        0x21 => 'Drum Voice GM',        //  (nn = 0)
        0x28 => 'Drum Voice USER',      //  (nn = 0 – 31)
        0x29 => 'reserved',             // 
        0x2F => 'Drum Voice Edit Buffer', //  (nn = 0)
        0x30 => 'Mixing Voice Edit Buffer', //  (nn = 0 – 15) nn: pa
        0x31 => 'Mixing Voice', //  (Current Song/Patt) (nn = 0 – 15; nn: Voice Number)
        0x40 => 'Performance USER 1', //  (nn = 0 – 127)
        0x41 => 'Performance USER 2', // ...
        0x42 => 'Performance USER 3', // ...
        0x43 => 'Performance USER 4', // ...
        0x4F => 'Performance Edit Buffer', //  (nn = 0)
        0x5F => 'Multi Edit Buffer', //  (nn = 0)
        0x70 => 'Master USER', // (nn = 0 – 127)
        0x7F => 'Master Edit Buffer', //  (nn = 0)
    );
}

function outit() {
    global $startMicrotime, $output;
    $endMicrotime = microtime(true);
    notice('Elapsed time: ' . ($endMicrotime - $startMicrotime) . 's.');

    notice('');
    notice('You can also use <a href="?output=syx">?output=syx</a> to download the file straight with browser.');

    if ($output == 'HTML') {
        closeHTMLOutput();
    } else if ($output == 'TXT') {
        closeTXTOutput();
    };
}

function initHTMLOutput() {
    global $programName, $programVersion, $programAuthor;
    echo '<html><head><title>' . $programName . ' - ' . $programVersion . ' by ' . $programAuthor . '</title></head>';
    echo '<body><h1>' . $programName . '</h1><h2>' . $programVersion . ' by ' . $programAuthor . '</h2><blockquote style="line-height:120%;"><tt>';
}

function closeHTMLOutput() {
    global $programAuthor, $programAuthorWebsite;
    echo '</tt></blockquote><footer>Thank you. / [<a href="'.$programAuthorWebsite.'">'.$programAuthor.'</a>]</footer></body></html>';
}

function initTXTOutput() {
    global $programName, $programVersion, $programAuthor;

    header('Content-Type: text/plain; charset=utf-8');
    echo $programName . "\n";
    echo $programVersion . ' by ' . $programAuthor . "\n";
    echo '';
}

function closeTXTOutput() {
    global $programAuthor, $programAuthorWebsite;
    echo 'Thank you. / '.$programAuthor.' ['.$programAuthorWebsite.']' .  "\n";
}

function loadWaveformsList($filename, $regexp, $flipped = false) {
    $waveforms = array();

    $lines = file($filename);
    $lines = array_map('stripCommentsFromLine', $lines);
    $lines = array_filter($lines);          // remove empty values

    foreach ($lines as $line) {
        preg_match($regexp, $line, $matches);
        if ($flipped) {
            // we can't use waveform number as key for the xf/xs fallback array,
            // because it has the same xs waveform number on various xf waveforms names.
            $waveforms[$matches[2]] = intval($matches[1]);
            $waveforms[$matches[2] . 'flipped'] = true;
        } else {
            $waveforms[intval($matches[1])] = $matches[2];
        }
    }
    return $waveforms;
}

function stripCommentsFromLine($txt) {
    if (strpos($txt, '#') !== false) {
        $txt = substr($txt, 0, strpos($txt, '#'));
    };
    $txt = trim($txt);
    return $txt;
}

function convertWaveNumberXFtoXS($waveNumberXF) {
    global $xfWaveforms, $xsWaveformsFlipped, $xsWaveforms;
    $waveformName = $xfWaveforms[$waveNumberXF];
    $xsWaveformNumber = $xsWaveformsFlipped[$waveformName];
    if ($xsWaveformNumber == '') {
        notice('There is no ' . str_pad($waveformName, 20) . ' [' . $waveNumberXF . '] on XS! No fallback yet, changing to ' . $xsWaveforms[1] . ' [1].');
        $xsWaveformNumber = 1;
    } else if ($xsWaveformsFlipped[$waveformName . 'flipped']) {
        notice('There is no ' . str_pad($waveformName, 20) . ' [' . $waveNumberXF . '] on XS! Falling back to ' . str_pad($xsWaveforms[$xsWaveformNumber], 20) . ' [' . $xsWaveformNumber . '].');
    };
    return $xsWaveformNumber;
}

function printXFWaveformsNotExistingOnXS() {
    global $xfWaveforms;
    for ($i = 1; $i < count($xfWaveforms); $i++) {
        $result = convertWaveNumberXFtoXS($i);
        if ($result == false) {
            echo ' ' . $xfWaveforms[$i] . ' #' . $i . "\n";
        }
    }
}

function notice($txt) {
    global $output;
    if ($output == 'TXT') {
        echo strip_tags($txt) . "\n";
    } else if ($output == 'HTML') {
        echo $txt . '<br />';
    };
}

function parseNiceSysEx($sysEx) {
    // returns 'f0 43 00 7f 03 00 5f 41 '... instead of binary data ;)
    return join(' ', str_split(bin2hex($sysEx), 2));
}
