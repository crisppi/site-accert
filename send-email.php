<?php
// configure with your Hostinger SMTP credentials
$smtpConfig = [
    'host' => 'smtps.uhserver.com',
    'port' => 465,
    'username' => 'faleconosco@accertconsult.com.br',
    'password' => 'Accert@2023',
    'from_email' => 'faleconosco@accertconsult.com.br',
    'from_name' => 'Accert Consult',
    'helo' => $_SERVER['SERVER_NAME'] ?? 'accertconsult.com.br',
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit;
}

$nome = trim($_POST['nome'] ?? '');
$email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$empresa = trim($_POST['empresa'] ?? '');
$mensagem = trim($_POST['mensagem'] ?? '');

if (!$nome || !$email || !$mensagem) {
    header('Location: contato.html?status=erro');
    exit;
}

$assunto = 'Contato pelo site Accert Consult';
$corpo = "Nome: $nome\nEmail: $email\nEmpresa: $empresa\nMensagem:\n$mensagem";

$toRecipients = ['faleconosco@accertconsult.com.br'];
$ccRecipients = ['crisppi@gmail.com'];
$allRecipients = array_merge($toRecipients, $ccRecipients);

$headers = [
    'Date: ' . date('r'),
    "From: {$smtpConfig['from_name']} <{$smtpConfig['from_email']}>",
    "Reply-To: $email",
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
];

$headerTo = 'To: ' . implode(', ', $toRecipients);
$headers[] = $headerTo;
$headers[] = 'Cc: ' . implode(', ', $ccRecipients);
$headers[] = "Subject: $assunto";

$message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", $corpo) . "\r\n";

$result = smtp_send($smtpConfig, $allRecipients, $message);
$statusDetail = $result['success'] ? 'enviado via SMTP' : $result['error'];

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/contact.log';
$logEntry = sprintf(
    "[%s] %s <%s> (%s) - %s\n",
    date('Y-m-d H:i:s'),
    $nome,
    $email,
    $empresa ?: 'sem empresa',
    $statusDetail
);
file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

if ($result['success']) {
    header('Location: obrigado.html');
    exit;
}

header('Location: contato.html?status=erro');
exit;

function smtp_send(array $config, array $recipients, string $message): array
{
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $socket = stream_socket_client(
        sprintf('tcp://%s:%d', $config['host'], $config['port']),
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        return ['success' => false, 'error' => "falha de conexão ({$errno}): {$errstr}"];
    }

    stream_set_blocking($socket, true);
    stream_set_timeout($socket, 30);

    if (!expectCode($socket, 220)) {
        fclose($socket);
        return ['success' => false, 'error' => 'serviço SMTP indisponível'];
    }

    if (!sendCommand($socket, "EHLO {$config['helo']}", [250])) {
        fclose($socket);
        return ['success' => false, 'error' => 'EHLO falhou'];
    }

    if (!sendCommand($socket, 'STARTTLS', [220])) {
        fclose($socket);
        return ['success' => false, 'error' => 'STARTTLS não suportado'];
    }
    stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

    if (!sendCommand($socket, "EHLO {$config['helo']}", [250])) {
        fclose($socket);
        return ['success' => false, 'error' => 'EHLO pós-STARTTLS falhou'];
    }

    if (!sendCommand($socket, 'AUTH LOGIN', [334])) {
        fclose($socket);
        return ['success' => false, 'error' => 'AUTH LOGIN falhou'];
    }

    if (!sendCommand($socket, base64_encode($config['username']), [334])) {
        fclose($socket);
        return ['success' => false, 'error' => 'usuário SMTP inválido'];
    }

    if (!sendCommand($socket, base64_encode($config['password']), [235])) {
        fclose($socket);
        return ['success' => false, 'error' => 'senha SMTP inválida'];
    }

    if (!sendCommand($socket, "MAIL FROM:<{$config['from_email']}>", [250])) {
        fclose($socket);
        return ['success' => false, 'error' => 'MAIL FROM rejeitado'];
    }

    foreach ($recipients as $recipient) {
        if (!sendCommand($socket, "RCPT TO:<$recipient>", [250, 251])) {
            fclose($socket);
            return ['success' => false, 'error' => "destinatário $recipient rejeitado"];
        }
    }

    if (!sendCommand($socket, 'DATA', [354])) {
        fclose($socket);
        return ['success' => false, 'error' => 'DATA rejeitado'];
    }

    fwrite($socket, $message);
    fwrite($socket, "\r\n.\r\n");

    if (!expectCode($socket, 250)) {
        fclose($socket);
        return ['success' => false, 'error' => 'envio do corpo rejeitado'];
    }

    sendCommand($socket, 'QUIT', [221]);
    fclose($socket);
    return ['success' => true];
}

function expectCode($socket, int $code): bool
{
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return trim(substr($response, 0, 3)) == $code;
}

function sendCommand($socket, string $command, array $expected): bool
{
    fwrite($socket, $command . "\r\n");
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    $code = trim(substr($response, 0, 3));
    return in_array($code, $expected, true);
}
