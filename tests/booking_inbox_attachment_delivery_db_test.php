<?php
/**
 * DB-backed Booking Inbox reply attachment linkage and MIME delivery path.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Panic\Auth;
use Panic\Database;
use Panic\Env;
use Panic\LeadsInbox;
use Panic\Request;
use Panic\Response;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$passed = 0;
$failed = 0;
function ok(bool $condition, string $label): void
{
    global $passed, $failed;
    echo ($condition ? "  ✓ " : "  ✗ FAIL: ") . $label . "\n";
    $condition ? $passed++ : $failed++;
}
function responseData(Response $response): array
{
    $reflection = new ReflectionClass($response);
    return [
        'status' => $reflection->getProperty('status')->getValue($response),
        'body' => $reflection->getProperty('body')->getValue($response),
    ];
}

echo "\n=== Booking Inbox attachment delivery (DB-backed) ===\n\n";

$db = new Database();
$admin = $db->one("SELECT * FROM users WHERE role = 'venue_admin' AND is_hidden = 0 ORDER BY id LIMIT 1");
if ($admin === null) {
    fwrite(STDERR, "A visible venue administrator is required for this test.\n");
    exit(1);
}

$marker = bin2hex(random_bytes(4));
$email = "attachment-{$marker}@example.invalid";
$leadId = $db->insert(
    "INSERT INTO leads (status,source,contact_name,contact_email,event_name)
     VALUES ('new','other','Attachment Test',?,?)",
    [$email, "PB Attachment {$marker}"]
);
$dir = $root . '/public/uploads/leads/' . $leadId;
$storedName = "attachment-{$marker}.txt";
$path = $dir . '/' . $storedName;
$bytes = "Booking Inbox attachment test {$marker}\n";
mkdir($dir, 0775, true);
file_put_contents($path, $bytes);
$attachmentId = $db->insert(
    'INSERT INTO lead_attachments
     (lead_id,filename,mime_type,size_bytes,storage_path,uploaded_by_user_id)
     VALUES (?,?,?,?,?,?)',
    [$leadId, 'rider.txt', 'text/plain', strlen($bytes), 'uploads/leads/' . $leadId . '/' . $storedName, $admin['id']]
);
$oldSendmail = getenv('SENDMAIL_PATH');

try {
    putenv('SENDMAIL_PATH=/bin/true');
    $auth = new Auth();
    $auth->setUser($admin);
    $response = responseData((new LeadsInbox(
        $db,
        $auth,
        ['leadId' => $leadId, 'child' => 'messages'],
        $root
    ))->handle(new Request('POST', '/', [], [
        'direction' => 'outbound',
        'subject' => 'Attachment delivery test',
        'body_text' => 'Please see the attached rider.',
        'idempotency_key' => 'attachment-' . $marker,
        'attachment_ids' => [$attachmentId],
    ], [], [])));

    $messageId = (int) ($response['body']['message']['id'] ?? 0);
    ok($response['status'] === 200 && $messageId > 0, 'reply with an attachment is accepted');
    ok(($response['body']['message']['status'] ?? null) === 'sent', 'message is marked sent only after transport acceptance');

    $attachment = $db->one('SELECT message_id FROM lead_attachments WHERE id = ?', [$attachmentId]);
    ok((int) $attachment['message_id'] === $messageId, 'attachment is linked to the canonical outbound message');
} finally {
    if ($oldSendmail === false) {
        putenv('SENDMAIL_PATH');
    } else {
        putenv('SENDMAIL_PATH=' . $oldSendmail);
    }
    $db->run('DELETE FROM outbox WHERE to_address = ?', [$email]);
    $db->run('DELETE FROM leads WHERE id = ?', [$leadId]);
    @unlink($path);
    @rmdir($dir);
    foreach (glob($root . '/storage/mail/*' . $email . '*.eml') ?: [] as $mailFile) {
        @unlink($mailFile);
    }
}

echo "\nBooking Inbox attachment delivery: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
