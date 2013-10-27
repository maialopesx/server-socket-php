
<?php

###################################################################
# tracker is developped with GPL Licence 2.0
##!/usr/bin/php -q
# GPL License: http://www.gnu.org/licenses/old-licenses/gpl-2.0.txt
#
# Developped by : Cyril Feraudet
#
###################################################################
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
#    For information : cyril@feraudet.com
####################################################################
/**
 * Database creation script
 * CREATE DATABASE `tracker` DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci;
 * USE `tracker`;
 * CREATE TABLE IF NOT EXISTS `gprmc` (
 *   `id` int(11) NOT NULL AUTO_INCREMENT,
 *   `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *   `imei` varchar(17) NOT NULL,
 *   `phone` varchar(20) DEFAULT NULL,
 *   `trackerdate` varchar(10) NOT NULL,
 *   `satelliteDerivedTime` varchar(10) NOT NULL,
 *   `satelliteFixStatus` char(1) NOT NULL,
 *   `latitudeDecimalDegrees` varchar(12) NOT NULL,
 *   `latitudeHemisphere` char(1) NOT NULL,
 *   `longitudeDecimalDegrees` varchar(12) NOT NULL,
 *   `longitudeHemisphere` char(1) NOT NULL,
 *   `speed` float NOT NULL,
 *   `bearing` float NOT NULL,
 *   `utcDate` varchar(6) NOT NULL,
 *   `checksum` varchar(10) NOT NULL,
 *   `gpsSignalIndicator` char(1) NOT NULL,
 *   `other` varchar(50) DEFAULT NULL,
 *   PRIMARY KEY (`id`),
 *   KEY `imei` (`imei`)
 * ) ENGINE=MyISAM  DEFAULT CHARSET=latin1;
 */
/**
 * Listens for requests and forks on each connection
 */
$ip = '192.210.139.43';
$port = 1025;

$__server_listening = true;

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();
declare(ticks = 1);

if (!isset($argv[1]) || $argv[1] != '-f') {
    become_daemon();
}

/* nobody/nogroup, change to your host's uid/gid of the non-priv user */
change_identity(0, 0);

/* handle signals */
pcntl_signal(SIGTERM, 'sig_handler');
pcntl_signal(SIGINT, 'sig_handler');
pcntl_signal(SIGCHLD, 'sig_handler');

/* change this to your own host / port */
server_loop($ip, $port);

/**
 * Change the identity to a non-priv user
 */
function change_identity($uid, $gid) {
    if (!posix_setgid($gid)) {
        print "Unable to setgid to " . $gid . "!\n";
        exit;
    }

    if (!posix_setuid($uid)) {
        print "Unable to setuid to " . $uid . "!\n";
        exit;
    }
}

/**
 * Creates a server socket and listens for incoming client connections
 * @param string $address The address to listen on
 * @param int $port The port to listen on
 */
function server_loop($address, $port) {
    GLOBAL $__server_listening;

    if (($sock = socket_create(AF_INET, SOCK_STREAM, 0)) < 0) {
        echo "failed to create socket: " . socket_strerror($sock) . "\n";
        exit();
    }

    if (($ret = socket_bind($sock, $address, $port)) < 0) {
        echo "failed to bind socket: " . socket_strerror($ret) . "\n";
        exit();
    }

    if (( $ret = socket_listen($sock, 0) ) < 0) {
        echo "failed to listen to socket: " . socket_strerror($ret) . "\n";
        exit();
    }

    socket_set_nonblock($sock);

    echo "waiting for clients to connect\n";

    while ($__server_listening) {
        $connection = @socket_accept($sock);
        if ($connection === false) {
            usleep(100);
        } elseif ($connection > 0) {
            handle_client($sock, $connection);
        } else {
            echo "error: " . socket_strerror($connection);
            die;
        }
    }
}

/**
 * Signal handler
 */
function sig_handler($sig) {
    switch ($sig) {
        case SIGTERM:
        case SIGINT:
            exit();
            break;

        case SIGCHLD:
            pcntl_waitpid(-1, $status);
            break;
    }
}

/**
 * Handle a new client connection
 */
function handle_client($ssock, $csock) {
    GLOBAL $__server_listening;

    $pid = pcntl_fork();

    if ($pid == -1) {
        /* fork failed */
        echo "fork failure!\n";
        die;
    } elseif ($pid == 0) {
        /* child process */
        $__server_listening = false;
        socket_close($ssock);
        interact($csock);
        socket_close($csock);
    } else {
        socket_close($csock);
    }
}

function interact($socket) {
    /* TALK TO YOUR CLIENT */
    $rec = "";
    socket_recv($socket, $rec, 4848, 10);
    $list1 = explode("A", $rec);
    $list2 = explode("W", $list1[1]);
    $latitudeLongitude = $list2[0];
    $list3 = explode('S', $latitudeLongitude);
    $latitude = $list3[0];
    $longitude = $list3[1];
    //var_dump($latitude.' -- '.$longitude);
    $latitudeFormatada = removeZeroDoInicio($latitude);
    $longitudeFormatada = removeZeroDoInicio($longitude);
    $latitudeGogle = calculaLatitudeLongitude($latitudeFormatada);
    $longitudeGogle = calculaLatitudeLongitude($longitudeFormatada);
    
    enviarDadosPost(getNumeroTelefone($rec), $latitudeGogle, $longitudeGogle);
    var_dump($latitudeGogle . ',' . $longitudeGogle . ' -> Numero:' . getNumeroTelefone($rec));
}

/**
 * Become a daemon by forking and closing the parent
 */
function become_daemon() {
    $pid = pcntl_fork();

    if ($pid == -1) {
        /* fork failed */
        echo "fork failure!\n";
        exit();
    } elseif ($pid) {
        /* close the parent */
        exit();
    } else {
        /* child becomes our daemon */
        posix_setsid();
        chdir('/');
        umask(0);
        return posix_getpid();
    }
}

function removeZeroDoInicio($str) {
    $verdade = true;
    $i = 0;
    while ($verdade) {
        if ($str[$i] == '0') {
            $str = ltrim($str, $str[$i]);
            $i++;
        } else {
            $verdade = false;
        }
    }
    return $str;
}

function calculaLatitudeLongitude($valor) {
    $list5 = explode('.', $valor);
    if (strlen($list5[0]) > 3) {
        $grau = $list5[0][0] . $list5[0][1];
    } else {
        echo 'Erro definição do grau';
    }

    $minuto = $list5[0][2] . $list5[0][3] . '.' . $list5[1];
    return $grau + ($minuto / 60);
}

function getNumeroTelefone($srt) {
    $list = explode('B', $srt);
    if (strlen($list[0]) != NULL) {
        $newList = explode('(', $list[0]);
        return $newList[1];
    } else {
        return 'Erro definição do numero do celular';
    }
}

function enviarDadosPost($telefone,$latitude,$longitude) {
    $url = 'http://locatebus.com/requisicao';
    $data = array('telefone' => $telefone, 'latitude' => $latitude, 'longitude' => $longitude);

// use key 'http' even if you send the request to https://...
    $options = array(
        'http' => array(
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
        ),
    );
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
}

?>
