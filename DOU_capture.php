#!/usr/bin/php
<?php

/*
    Jornal #1: Diário Oficial da União, seção 1: Leis, decretos, resoluções, instruções normativas, portarias e outros atos normativos de interesse geral
    Jornal #2: Diário Oficial da União, seção 2: Atos de interesse dos servidores da Administração Pública Federal
    Jornal #3: Diário Oficial da União, seção 3: Contratos, editais, avisos ineditoriais
*/

/*
    Estão disponíveis as matérias desde o dia 1 de janeiro de 1998.
*/

/* Estrutura de arquivos
    dou_20091210_s3.pdf => Diario Oficial, publicado em 10 de dezembro de 2009, seção 3
    .tmp/dou_20091210_s3_index_1.htm => Frameset da pagina 1
    .tmp/dou_20091210_s3_doupdf_1.pdf => Arquivo de PDF carregado pelo frameset
    .tmp/dou_20091210_s3_doupdf_1.ps => Arquivo Postscript convertido a partir do PDF
    .tmp/dou_20091210_s3.ps => Um unico arquivo Postscript com todas as paginas concatenadas
*/

$hora_agora = time ();
$hora_inicio = mktime (0, 0, 0, 1, 1, 1995);
$pasta_base = dirname (__FILE__);
$temp_dir = $pasta_base . "/.tmp";
define ('MIMETYPE_PDF', "application/pdf");
define ('MIMETYPE_HTML', "text/html");

# putenv ("http_proxy=http://127.0.0.1:3128/");

//////////////////////////////////////

if ((~$_SERVER['argc'] & 1) || ($_SERVER['argc'] < 2)) {
    fatal ("Uso: " . $_SERVER['argv'][0] . " <data> <secao> [<data> <secao> [<data> <secao> [...]]]");
}
if (! is_dir ($temp_dir)) {
    if (! mkdir ($temp_dir, 0700)) {
        fatal ("Impossivel criar pasta de trabalho!");
    }
}
for ($i = 1; $i < $_SERVER['argc']; $i += 2) {
    baixa_dou_secao ($_SERVER['argv'][$i], $_SERVER['argv'][$i+1]);
}
echo ("\nConcluido.\n");

//////////////////////////////////////

function constroi_dou_secao ($data, $secao) {
    global $temp_dir, $pasta_base;
    $data_real = formatos_data ($data);
    $numpags = detecta_numero_paginas ($data, $secao);
    $pdfjoin_args = "";
    $ignorecache = false;
    aviso ("Obtendo as paginas da secao " . $secao . " do DOU publicado em " . $data_real['human'] . "...");
    for ($i = 1; $i <= $numpags; $i++) {
        $frameset = obtem_frameset ($data, $secao, $i, $numpags, $ignorecache);
        if (preg_match ("/<frame\\s+name=[\"']?visualizador[\"']?\\s+src=[\"']?([^\\s]+)[\"']?\\s*\\/?>/is", $frameset['dados'], $matr)) {
            $prich = substr ($matr[1], 0, 1);
            if ($prich == "'" || $prich == '"') {
                $matr[1] = substr ($matr[1], 1);
            }
            $ultch = substr ($matr[1], -1);
            if ($ultch == "'" || $ultch == '"') {
                $matr[1] = substr ($matr[1], 0, strlen ($matr[1]) - 1);
            }
            # Nao eh uma maneira elegante de resolver URL's relativas, mas... Funciona.
            if (substr ($matr[1], 0, 6) == "../../") {
                $matr[1] = "http://pesquisa.in.gov.br/imprensa/" . substr ($matr[1], 6);
            }
            $comeu = false;
            $arq_pdf = "dou_" . $data_real['file'] . "_s" . $secao . "_doupdf_" . $i;
            $fpath_pdf = $temp_dir . "/" . $arq_pdf;
            while (true) {
                if (obtem_arquivo_se_nao_tem (
                        $arq_pdf . ".pdf",
                        $matr[1],
                        $frameset['url'],
                        MIMETYPE_PDF,
                        $ignorecache,
                        false
                    )) {
                    if (converte_pdf_para_ps ($fpath_pdf . ".pdf", "/dev/null")) {
                        $pdfjoin_args .= " " . escapeshellarg ($fpath_pdf . ".pdf");
                        $ignorecache = false;
                        break;
                    } else if ($comeu) {
                        if ($ignorecache) {
                            fatal ("Arquivo '" . $fpath_pdf . ".pdf' esta corrompido!");
                        } else {
                            $ignorecache = true;
                            $i--;
                            break;
                        }
                    } else {
                        unlink ($fpath_pdf . ".pdf");
                        $comeu = true;
                    }
                } else if ($ignorecache) {
                    fatal ("Impossivel baixar arquivo PDF da pagina " . $i . " da secao " . $secao . " do DOU publicado em " . $data_real['human'] . "!");
                } else {
                    $ignorecache = true;
                    $i--;
                    break;
                }
            }
        } else {
            fatal ("Impossivel determinar URL da pagina " . $i . " da secao " . $secao . " do DOU publicado em " . $data_real['human'] . "!");
        }
    }
    aviso ("Mesclando arquivos PDF...");
    executa_comando ("pdfjoin --no-tidy --outfile " . escapeshellarg ($pasta_base . "/dou_" . $data_real['file'] . "_s" . $secao . ".pdf") . " " . $pdfjoin_args);
}

function converte_pdf_para_ps ($entrada, $saida) {
    if (testa_mimetype ($entrada, MIMETYPE_PDF)) {
        exec ("pdftops " . escapeshellarg ($entrada) . " " . escapeshellarg ($saida) . " 2>&1", $saida, $retvar);
        if ($retvar === 0) {
            if (trim (implode ("", $saida)) != "") {
                aviso ("O comando 'pdftops' aplicado ao arquivo '" . $entrada . "' nao deveria produzir saida!");
            } else {
                return (true);
            }
        }
    }
    return (false);
}

function testa_mimetype ($fpath, $mime = false) {
    $retorno = true;
    $saida = "";
    if ($mime !== false) {
        $saida = trim (implode ("", executa_comando ("file --mime-type --brief " . escapeshellarg ($fpath))));
        if (empty ($saida)) {
            fatal ("Comando 'file' nao retornou uma saida valida!");
        }
        if ($mime != $saida) {
            $retorno = false;
        }
    }
    if (! $retorno) {
        aviso ("Arquivo '" . basename ($fpath) . "' tem o mimetype '" . $saida . "', mas era desejado '" . $mime . "'. Apagando o arquivo...");
        unlink ($fpath);
    }
    return ($retorno);
}

function baixa_dou_secao ($data, $secao, $naoconstroi = false) {
    global $pasta_base;
    $data_real = formatos_data ($data);
    $fpath = $pasta_base . "/dou_" . $data_real['file'] . "_s" . $secao . ".pdf";
    if (file_exists ($fpath)) {
        if (testa_mimetype ($fpath, MIMETYPE_PDF)) {
            if (converte_pdf_para_ps ($fpath, "/dev/null")) {
                return;
            }
        }
    }
    if ($naoconstroi) {
        fatal ("Impossivel construir secao " . $secao . " do DOU publicado em " . $data_real['human'] . "!");
    }
    constroi_dou_secao ($data, $secao);
    baixa_dou_secao ($data, $secao, true);
}

function fatal ($msg) {
    echo ("\n **** " . $msg . " ****\n");
    exit (1);
}

function aviso ($msg) {
    echo ("\n ---- " . $msg . " ----\n");
}

function formatos_data ($data) {
    global $hora_agora, $hora_inicio;
    if (preg_match ("/^(\\d\\d?)\\/(\\d\\d?)\\/(\\d\\d\\d\\d)\$/", $data, $matr)) {
        if (strlen ($matr[1]) < 2) {
            $matr[1] = "0" . $matr[1];
        }
        if (strlen ($matr[2]) < 2) {
            $matr[2] = "0" . $matr[2];
        }
        $data = $matr[3] . $matr[2] . $matr[1];
    }
    if (preg_match ("/^(\\d\\d\\d\\d)(\\d\\d)(\\d\\d)\$/", $data, $matr)) {
        $ano = intval ($matr[1], 10);
        $mes = intval ($matr[2], 10);
        $dia = intval ($matr[3], 10);
        $human = $matr[3] . "/" . $matr[2] . "/" . $matr[1];
        $tstmp = mktime (0, 0, 0, $mes, $dia, $ano);
        if ($tstmp === false) {
            fatal ("Data invalida: '" . $dia . "/" . $mes . "/" . $ano . "'!");
        }
        if ($tstmp < $hora_inicio || $tstmp > $hora_agora) {
            fatal ("Diario oficial nao disponivel na data: '" . $dia . "/" . $mes . "/" . $ano . "'!");
        }
        return (array ('human' => $human, 'file' => $data, 'timestamp' => $tstmp));
    } else {
        fatal ("Formato de data invalido: '" . $data . "'!");
    }
}

function executa_comando ($comando, $ignorar_saida = false) {
    exec ($comando, $saida, $retvar);
    if ($retvar !== 0) {
        if ($ignorar_saida) {
            return (false);
        } else {
            fatal ("Comando '" . $comando . "' terminou com estado de saida '" . $retvar . "'!");
        }
    }
    return ($saida);
}

function obtem_arquivo_se_nao_tem ($arquivo, $url_download = false, $url_referer = false, $mime = false, $ignorecache = false, $no_continue = false) {
    global $temp_dir;
    static $cookies_file = false;
    $fpath = $temp_dir . "/" . $arquivo;
    if (! $ignorecache) {
        if (file_exists ($fpath)) {
            $fsz = filesize ($fpath);
            if ($fsz === false) {
                fatal ("Impossivel determinar tamanho do arquivo '" . $arquivo . "'!");
            }
            if ($fsz > 0) {
                if (testa_mimetype ($fpath, $mime)) {
                    echo (".");
                    return (true);
                }
            }
        }
    }
    aviso ("Arquivo '" . $arquivo . "' eh vazio ou nao existe. Efetuando o download...");
    if (empty ($url_download)) {
        aviso ("Impossivel baixar arquivo '" . $arquivo . "'! URL de origem nao foi especificada!");
        return (false);
    }
    if (empty ($cookies_file)) {
        $cookies_file = trim (implode ("", executa_comando ("tempfile")));
        if (empty ($cookies_file) || (! file_exists ($cookies_file))) {
            fatal ("Impossivel criar arquivo de cookies temporario!");
        }
    }
    $wgetcmd = "wget --user-agent=" . escapeshellarg ("Mozilla/5.0 (X11; U; Linux i686; pt-BR; rv:1.9.1.7) Gecko/20100106 Ubuntu/10.04 (lucid) Alexa Firefox/3.5.7") . " " .
               "--progress=dot --tries=20 --timeout=30 -O " . escapeshellarg ($fpath) . " --keep-session-cookies --no-check-certificate " .
               "--load-cookies " . escapeshellarg ($cookies_file) . " --save-cookies " . escapeshellarg ($cookies_file);
    if (! empty ($url_referer)) {
        $wgetcmd .= " --referer=" . escapeshellarg ($url_referer);
    }
    if (! $no_continue) {
        $wgetcmd .= " --continue";
    }
    $wgetcmd .= " " . escapeshellarg ($url_download);
    executa_comando ($wgetcmd);
    return (obtem_arquivo_se_nao_tem ($arquivo, false, false, $mime, false, $no_continue));
}

function le_arquivo_e_baixa_se_nao_tem ($arquivo, $url_download = false, $url_referer = false, $mime = false, $ignorecache = false, $no_continue = false) {
    global $temp_dir;
    if (obtem_arquivo_se_nao_tem ($arquivo, $url_download, $url_referer, $mime, $ignorecache, $no_continue)) {
        $dados = file_get_contents ($temp_dir . "/" . $arquivo);
        if ($dados === false) {
            fatal ("Erro abrindo arquivo '" . $arquivo . "'!");
        }
        return ($dados);
    } else {
        return (false);
    }
}

function obtem_frameset ($data_public, $secao, $pagina, $totalarquivos = false, $ignorecache = false) {
    $data_real = formatos_data ($data_public);
    $url_dou = "http://pesquisa.in.gov.br/imprensa/jsp/visualiza/index.jsp?data=" . $data_real['human'] . "&jornal=" . $secao . "&pagina=" . $pagina;
    if ($totalarquivos !== false) {
        $url_dou .= "&totalArquivos=" . $totalarquivos;
    }
    $dados = le_arquivo_e_baixa_se_nao_tem (
        "dou_" . $data_real['file'] . "_s" . $secao . "_index_" . $pagina . ".htm",
        $url_dou,
        "http://pesquisa.in.gov.br/imprensa/core/consulta2.action",
        MIMETYPE_HTML,
        $ignorecache,
        true
    );
    if ($dados === false) {
        fatal ("Impossivel ler HTML de frameset da pagina " . $pagina . " da secao " . $secao . " do DOU publicado em " . $data_real['human'] . "!");
    }
    return (array ('url' => $url_dou, 'dados' => $dados));
}

function detecta_numero_paginas ($data_public, $secao) {
    $data_real = formatos_data ($data_public);
    echo ("Detectando numero de paginas da secao " . $secao . " do DOU publicado em " . $data_real['human'] . "...");
    $index_jsp = obtem_frameset ($data_public, $secao, 1, false, true);
    if (preg_match ("/<frame\\s+name=['\"]?controlador['\"]?\\s+src=['\"]?\\.\\.\\/visualiza\\/navegaJornal(|Sumario)\\.jsp\\?([^\\s]+)['\"]?\\s+scrolling=['\"]?no['\"]?\\s*\\/?>/is", $index_jsp['dados'], $matr)) {
        $ultch = substr ($matr[2], -1);
        if ($ultch == "'" || $ultch == '"') {
            $matr[2] = substr ($matr[2], 0, strlen ($matr[2]) - 1);
        }
        $qstr = explode ("&", $matr[2]);
        foreach ($qstr as $item) {
            if (substr ($item, 0, 14) == "totalArquivos=") {
                $tot_arq = intval (substr ($item, 14), 10);
                if ($tot_arq > 1) {
                    echo (". [" . $tot_arq . " paginas]\n");
                    return ($tot_arq);
                }
            }
        }
    }
    fatal ("Impossivel determinar o numero de paginas da secao " . $secao . " do DOU publicado em " . $data_real['human'] . "!");
}
