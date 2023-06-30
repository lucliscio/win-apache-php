<?php
$file = "C:\\Windows\\System32\\drivers\\etc\\hosts";
$conf_folder = __DIR__.'\Apache-2.4-win64\conf\configs';
$lines = file($file, FILE_IGNORE_NEW_LINES);
$php_folders = [
    'php53' => 'php-5.3-Win32-VC9-x64',
    'php54' => 'php-5.4-Win32-VC9-x64',
    'php55' => 'php-5.5-Win32-VC11-x64',
    'php56' => 'php-5.6-Win32-VC11-x64',
    'php70' => 'php-7.0-Win32-VC14-x64',
    'php71' => 'php-7.1-Win32-VC14-x64',
    'php72' => 'php-7.2-Win32-VC15-x64',
    'php73' => 'php-7.3-Win32-VC15-x64',
    'php74' => 'php-7.4-Win32-VC15-x64',
    'php80' => 'php-8.0-Win32-vs16-x64',
    'php81' => 'php-8.1-Win32-vs16-x64',
    'php82' => 'php-8.2-Win32-vs16-x64',
];

function show_usage()
{
    print("
This utility creates virtualhosts in apache and add the domain in 'hosts' file.

usage:
    - vh show <domain> <folder> <php>
        Shows the configuration that will be generated for the virtualhost. The
        'php' parameter is optional but can be from 'php53' to 'php82'.

    - vh add <domain> <folder> <php>
        Add a <domain> with it's virtual host in <folder>. The
        'php' parameter is optional but can be from 'php53' to 'php82'.

    - vh remove <domain>
        Removes a <domain> and it's virtual host

    - vh list
        Lists all domains with it's own virtual host
");
}

function generate_conf($domain, $folder, $php)
{
    global $php_folders;
    $lines = [];
    $lines[] = "<VirtualHost *:".get_apache_port().">";
    $lines[] = "\tServerName $domain";
    $lines[] = "\tErrorLog \"logs/$domain-error.log\"";
    $lines[] = "\tCustomLog \"logs/$domain-access.log\" common";
    $lines[] = "\tAlias /phpinfo \${WAP_SERVER}/Apache-2.4-win64/htdocs/phpinfo.php";
    $lines[] = "\tDocumentRoot $folder";
    $lines[] = "\t<Directory \"$folder\">";
    $lines[] = "\t\tOptions +Indexes +FollowSymLinks";
    $lines[] = "\t\tAllowOverride All";
    $lines[] = "\t\tRequire all granted";
    $lines[] = "\t</Directory>";

    if($php && $php != 'php82') {
        if(isset($php_folders[$php])) {
            $lines[] = "\tAddHandler fcgid-script .php";
            $lines[] = "\tFcgidWrapper \"\${WAP_SERVER}/".$php_folders[$php]."/php-cgi.exe\" .php";
            $lines[] = "\tOptions +ExecCGI";
        }else{
            print('No existe configuración de php \''.$php.'\': Se usa php 8.2');
        }
    }

    $lines[] = "</VirtualHost>";
    return implode("\n",$lines);
}

function restart_apache()
{
    shell_exec(__DIR__.'\Apache-2.4-win64\bin\httpd.exe -k restart');
}

function get_apache_port()
{
    $httpd_conf = file_get_contents(__DIR__.'\Apache-2.4-win64\conf\httpd.conf');
    if(preg_match_all('/[^#]Listen (\d+)/im', $httpd_conf, $matches)){
        if(count($matches[1])==1){
            return $matches[1][0];
        }
        throw new RuntimeException('Se ha encontrado más de un puerto de apache');
    }
    throw new RuntimeException('No se ha encontrado el puerto de apache');
}

if(count($argv) < 2){
    show_usage();
}else if($argv[1]=='show' and (count($argv)==4 || count($argv)==5)){
    $domain = $argv[2];
    $folder = $argv[3];
    $php = count($argv)==5 ? $argv[4] : 'php82';
    print(generate_conf($domain, $folder, $php));
}else if($argv[1]=='add'  and (count($argv)==4 || count($argv)==5)){
    $domain = $argv[2];
    $folder = $argv[3];
    $php = count($argv)==5 ? $argv[4] : 'php82';
    $conf_file = $conf_folder.'/'.$domain.'.conf';
    file_put_contents($conf_file, generate_conf($domain, $folder, $php));

    $found = false;
    foreach($lines as $number => $line){
        if(preg_match("/127.0.0.1\t$domain/", $line)){
            $found = true;
            break;
        }
    }
    if(!$found){
        $lines[] = "127.0.0.1\t$domain";
        file_put_contents($file, implode("\r\n", $lines));
    }
    restart_apache();
    $port = get_apache_port();
    print('Nuevo host accesible en http://'.$domain.($port != 80 ? ':'.$port : ''));
}else if($argv[1]=='remove' and count($argv)==3){
    $domain = $argv[2];
    $conf_file = $conf_folder.'/'.$domain.'.conf';
    if(file_exists($conf_file)){
        unlink($conf_file);
    }

    foreach($lines as $number => $line){
        if(preg_match("/127.0.0.1\t$domain/", $line)){
            unset($lines[$number]);
        }
    }
    file_put_contents($file, implode("\r\n", $lines));
    restart_apache();
}else if($argv[1]=='list'){

    $dominios = [];

    foreach($lines as $number => $line){
        if(preg_match("/127.0.0.1\t([\w.-]+)$/", $line, $matches)){
            $dominios[$matches[1]] = [
                'domain' => $matches[1],
                'php' => 'php82',
                'folder' => '',
            ];
        }
    }

    $domain_max_length = 0;
    $folder_max_length = 0;
    foreach($dominios as $dominio => $valor){
        $domain_max_length = max($domain_max_length, strlen($dominio));
        $conf_file = $conf_folder.'/'.$dominio.'.conf';
        $lineasvh = file($conf_file);
        $host_validated = false;
        foreach($lineasvh as $number => $line){
            if(preg_match("/ServerName (.*)/", $line, $matches)){
                if($matches[1] != $dominio){
                    throw new RuntimeException('El dominio {'.$dominio.'} tiene configurado un host diferente {'.$matches[1].'}');
                }
                $host_validated = true;
            }
            if(preg_match("/DocumentRoot (.*)$/", $line, $matches)){
                $dominios[$dominio]['folder'] = $matches[1];
                $folder_max_length = max($folder_max_length, strlen($matches[1]));
            }
            if(preg_match("/{WAP_SERVER}\/(.*)\/php-cgi.exe/", $line, $matches)){
                $dominios[$dominio]['php'] = array_search($matches[1], $php_folders);
            }
        }
    }

    ksort($dominios);

    $port = get_apache_port();
    if($port != 80){
        $domain_max_length += strlen($port) + 1; //puerto y los dos puntos
    }
    $domain_max_length += 7; // 'http://'
    print(str_pad('',$domain_max_length + $folder_max_length + 9, '-').PHP_EOL);
    foreach ($dominios as $dominio){
        $url = 'http://'.$dominio['domain'];
        if($port!= 80){
            $url .= ':'.$port;
        }
        print (str_pad($url,$domain_max_length).'  '.$dominio['php'].'  '.$dominio['folder'].PHP_EOL);
    }
}else{
    show_usage();
}

