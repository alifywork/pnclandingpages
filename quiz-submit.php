<?php
/**
 * PNC Logos – 30-second quiz handler
 * ---------------------------------------------------------------------------
 * Receives the quiz from Main.html, Ecommerce.html and Traffic.html
 * (name, email, phone, website + the 5 answers) and sends two emails:
 *   1. a lead notification to the admin (ADMIN_EMAIL), Reply-To = the client
 *   2. the quiz result to the client who took the quiz, Reply-To = the admin
 *
 * Requirements: PHP 7.1+ on hosting where PHP mail() works (cPanel, Plesk,
 * Hostinger, etc.). Upload this file to the same folder as the HTML pages.
 * The result is recalculated here from the answers, so the emails cannot be
 * filled with arbitrary text.
 */

declare(strict_types=1);

/* ============================ Settings ============================ */
const ADMIN_EMAIL = 'solutions@pnclogos.com';   // receives every quiz lead
const FROM_EMAIL  = 'no-reply@pnclogos.com';    // use a mailbox on your own domain (better delivery)
const FROM_NAME   = 'PNC Logos';
const BOOKING_URL = 'https://calendly.com/fancy-pnclogos/1-1-alignable-meet';
const SITE_URL    = 'https://www.pnclogos.com';
const PHONE_LABEL = '+1 800-213-7640';
const PHONE_LINK  = '+18002137640';
const TIMEZONE    = 'America/New_York';         // Orlando, FL
const RATE_LIMIT  = 5;                          // max submissions per IP address ...
const RATE_WINDOW = 900;                        // ... within this many seconds (15 min)
const PAGES       = ['Main', 'Ecommerce', 'Traffic'];
/* ================================================================== */

/* Keep QUIZ and RESULTS in sync with the quiz in the HTML pages.
   Each option is [label, visibility score, leads score, tracking score]. */
const QUIZ = [
    ['q' => 'What kind of business do you run?', 'o' => [
        ['Local service business', 0, 0, 0],
        ['Online store', 0, 0, 0],
        ['Professional practice (law, health, finance)', 0, 0, 0],
        ['Something else', 0, 0, 0],
    ]],
    ['q' => 'Where do most of your leads come from today?', 'o' => [
        ['Google searches', 0, 1, 0],
        ['Social media or ads', 0, 1, 0],
        ['Referrals and word of mouth', 1, 0, 0],
        ["I'm not getting many", 2, 0, 0],
    ]],
    ['q' => 'How many people visit your website in a month?', 'o' => [
        ['Fewer than 100', 2, 0, 0],
        ['100 to 1,000', 1, 1, 0],
        ['More than 1,000', 0, 2, 0],
        ["I don't know", 0, 0, 2],
    ]],
    ['q' => 'Are you running ads right now?', 'o' => [
        ['Yes', 0, 1, 1],
        ['I did in the past', 0, 0, 1],
        ['Never', 1, 0, 0],
    ]],
    ['q' => "What's your biggest problem?", 'o' => [
        ['Not enough people find me', 3, 0, 0],
        ['People visit but few get in touch', 0, 3, 0],
        ["I can't tell what's working", 0, 0, 3],
    ]],
];

const RESULTS = [
    [
        'key'    => 'visibility',
        'tag'    => 'Visibility',
        'title'  => 'Not enough people are finding you',
        'desc'   => 'Your business may be great, but too few people see it.',
        'checks' => ['Where you show up in Google searches', 'Your local listing and reviews', 'Which searches bring the right visitors'],
    ],
    [
        'key'    => 'leads',
        'tag'    => 'Leads',
        'title'  => "Visitors aren't turning into leads",
        'desc'   => "People arrive, but they don't get in touch.",
        'checks' => ['Where visitors drop off', 'Your forms, buttons and calls to action', 'Page speed and the mobile experience'],
    ],
    [
        'key'    => 'tracking',
        'tag'    => 'Tracking',
        'title'  => "You can't see what's working",
        'desc'   => "It's hard to know which channel brings you leads.",
        'checks' => ['Whether calls and forms are tracked', 'Which ads and channels bring leads', 'Where your ad spend is going'],
    ],
];

/* ---------------------------------------------------------------------------
   Request handling
   --------------------------------------------------------------------------- */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set(TIMEZONE);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 20000) {
    respond(413, ['ok' => false, 'error' => 'Request too large.']);
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST; // also accept a normal form post
}

// Spam protection: bots fill the hidden "company" field or submit instantly.
$elapsed = isset($data['elapsed_ms']) && is_numeric($data['elapsed_ms']) ? (int) $data['elapsed_ms'] : 0;
if (!empty($data['company']) || ($elapsed > 0 && $elapsed < 3000)) {
    respond(200, ['ok' => true]);
}

/* ---- validate ---- */
$name    = clean_text($data['name'] ?? '', 100);
$email   = clean_text($data['email'] ?? '', 150);
$phone   = clean_text($data['phone'] ?? '', 30);
$website = clean_text($data['website'] ?? '', 200);
$page    = in_array($data['page'] ?? '', PAGES, true) ? (string) $data['page'] : 'Main';
$pageUrl = clean_text($data['page_url'] ?? '', 300);
if ($pageUrl !== '' && !preg_match('~^https?://~i', $pageUrl)) {
    $pageUrl = '';
}

$errors = [];
if (text_length($name) < 2) {
    $errors['name'] = 'Please enter your name.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please enter a valid email address.';
}
$digits = (string) preg_replace('/\D+/', '', $phone);
if (!preg_match('/^[0-9+().\-\s]+$/', $phone) || strlen($digits) < 7 || strlen($digits) > 15) {
    $errors['phone'] = 'Please enter a valid phone number.';
}
if ($website !== '') {
    if (!preg_match('~^https?://~i', $website)) {
        $website = 'https://' . $website;
    }
    if (!preg_match('~^https?://([a-z0-9-]+\.)+[a-z]{2,}(:\d+)?(/\S*)?$~i', $website)) {
        $errors['website'] = 'Please enter a valid website address.';
    }
}

$answers = [];
$rawAnswers = $data['answers'] ?? null;
if (!is_array($rawAnswers) || count($rawAnswers) !== count(QUIZ)) {
    $errors['answers'] = 'Please answer all 5 questions.';
} else {
    foreach (QUIZ as $i => $question) {
        $value = $rawAnswers[$i] ?? null;
        if (!is_numeric($value) || (int) $value != $value || (int) $value < 0 || (int) $value >= count($question['o'])) {
            $errors['answers'] = 'Please answer all 5 questions.';
            break;
        }
        $answers[$i] = (int) $value;
    }
}

if ($errors) {
    respond(422, ['ok' => false, 'error' => 'Please check the highlighted fields.', 'fields' => $errors]);
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (rate_limited($ip)) {
    respond(429, ['ok' => false, 'error' => 'Too many submissions. Please try again later.']);
}
if (!function_exists('mail')) {
    respond(500, ['ok' => false, 'error' => 'Email is not available on this server.']);
}

/* ---- score the quiz (same rules as the pages) ---- */
$scores = [0, 0, 0];
$qa = [];
foreach (QUIZ as $i => $question) {
    $option = $question['o'][$answers[$i]];
    $scores[0] += $option[1];
    $scores[1] += $option[2];
    $scores[2] += $option[3];
    $qa[] = [$question['q'], $option[0]];
}
$best   = (int) array_search(max($scores), $scores, true);
$result = RESULTS[$best];

$lead = [
    'name'    => $name,
    'email'   => $email,
    'phone'   => $phone,
    'website' => $website,
    'page'    => $page,
    'pageUrl' => $pageUrl,
    'ip'      => $ip,
    'time'    => date('M j, Y g:i A T'),
];

/* ---- send ---- */
$adminSent = send_mail(
    ADMIN_EMAIL,
    'New quiz lead: ' . $name . ' (' . $result['tag'] . ', ' . $page . ' page)',
    admin_html($lead, $result, $qa),
    admin_text($lead, $result, $qa),
    $email,
    $name
);
$clientSent = send_mail(
    $email,
    'Your PNC Logos quiz result: ' . $result['title'],
    client_html($lead, $result, $qa),
    client_text($lead, $result, $qa),
    ADMIN_EMAIL,
    FROM_NAME
);

respond($adminSent || $clientSent ? 200 : 500, [
    'ok'     => $adminSent && $clientSent,
    'admin'  => $adminSent,
    'client' => $clientSent,
    'result' => $result['key'],
]);

/* ---------------------------------------------------------------------------
   Helpers
   --------------------------------------------------------------------------- */
function respond(int $status, array $payload)
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

/** One-line text: strips control characters (incl. CR/LF), collapses spaces, limits length. */
function clean_text($value, int $max): string
{
    $text = is_scalar($value) ? (string) $value : '';
    $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
}

function text_length(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

function h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rate_limited(string $ip): bool
{
    if ($ip === '') {
        return false;
    }
    $file = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'pnc-quiz-' . hash('sha256', $ip) . '.json';
    $now  = time();
    $hits = [];
    if (is_file($file)) {
        $saved = json_decode((string) @file_get_contents($file), true);
        $hits  = is_array($saved) ? $saved : [];
    }
    $hits = array_values(array_filter($hits, function ($t) use ($now) {
        return is_int($t) && $t > $now - RATE_WINDOW;
    }));
    if (count($hits) >= RATE_LIMIT) {
        return true;
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return false;
}

function encode_header(string $text): string
{
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

function send_mail(string $to, string $subject, string $html, string $text, string $replyTo, string $replyName): bool
{
    $boundary = 'pnc_' . bin2hex(random_bytes(12));
    $headers  = [
        'MIME-Version: 1.0',
        'From: ' . encode_header(FROM_NAME) . ' <' . FROM_EMAIL . '>',
        'Reply-To: ' . encode_header($replyName) . ' <' . $replyTo . '>',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text))
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . '--' . $boundary . "--\r\n";
    $params = filter_var(FROM_EMAIL, FILTER_VALIDATE_EMAIL) ? '-f' . FROM_EMAIL : '';

    return @mail($to, encode_header($subject), $body, implode("\r\n", $headers), $params);
}

/* ---------------------------------------------------------------------------
   Email templates (emails need inline styles to display correctly in mail apps)
   --------------------------------------------------------------------------- */
function email_layout(string $title, string $content): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#EEF1F6;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF1F6;"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #DCE2EC;border-radius:12px;font-family:Arial,Helvetica,sans-serif;color:#0B1B33;">'
        . '<tr><td style="height:6px;line-height:6px;font-size:0;background:#C4113A;border-radius:12px 12px 0 0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:24px 28px 4px;font-size:20px;font-weight:bold;letter-spacing:1px;color:#0A1F44;">PNC LOGOS</td></tr>'
        . '<tr><td style="padding:8px 28px 24px;">' . $content . '</td></tr>'
        . '<tr><td style="padding:16px 28px 24px;border-top:1px solid #DCE2EC;font-size:13px;line-height:1.6;color:#4B5565;">'
        . 'PNC Logos &middot; Orlando, FL &middot; <a href="' . h(SITE_URL) . '" style="color:#C4113A;">pnclogos.com</a>'
        . ' &middot; <a href="mailto:' . h(ADMIN_EMAIL) . '" style="color:#C4113A;">' . h(ADMIN_EMAIL) . '</a>'
        . ' &middot; <a href="tel:' . h(PHONE_LINK) . '" style="color:#C4113A;">' . h(PHONE_LABEL) . '</a>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function detail_rows(array $rows): string
{
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:15px;line-height:1.5;">';
    foreach ($rows as $row) {
        list($label, $value) = $row;
        $html .= '<tr><td style="padding:8px 12px 8px 0;border-bottom:1px solid #EEF1F6;color:#4B5565;vertical-align:top;width:150px;">' . h($label) . '</td>'
            . '<td style="padding:8px 0;border-bottom:1px solid #EEF1F6;color:#0B1B33;vertical-align:top;">' . $value . '</td></tr>';
    }
    return $html . '</table>';
}

function result_block(array $result): string
{
    $checks = '';
    foreach ($result['checks'] as $check) {
        $checks .= '<li style="margin:0 0 6px;">' . h($check) . '</li>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F6FA;border:1px solid #DCE2EC;border-radius:10px;">'
        . '<tr><td style="padding:18px 20px;">'
        . '<div style="font-size:12px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#C4113A;">Likely gap: ' . h($result['tag']) . '</div>'
        . '<div style="margin:6px 0 4px;font-size:20px;font-weight:bold;line-height:1.3;color:#0A1F44;">' . h($result['title']) . '</div>'
        . '<div style="font-size:15px;line-height:1.5;color:#3A4558;">' . h($result['desc']) . '</div>'
        . '<div style="margin:14px 0 6px;font-size:14px;font-weight:bold;color:#0A1F44;">What we&#39;ll check on your free audit call</div>'
        . '<ul style="margin:0;padding:0 0 0 20px;font-size:15px;line-height:1.5;color:#2B3648;">' . $checks . '</ul>'
        . '</td></tr></table>';
}

function answer_rows(array $qa): string
{
    $rows = [];
    foreach ($qa as $i => $pair) {
        $rows[] = [($i + 1) . '. ' . $pair[0], '<strong>' . h($pair[1]) . '</strong>'];
    }
    return detail_rows($rows);
}

function lead_rows(array $lead, bool $forAdmin): string
{
    $website = $lead['website'] !== ''
        ? '<a href="' . h($lead['website']) . '" style="color:#C4113A;">' . h($lead['website']) . '</a>'
        : 'Not provided';
    $rows = [
        ['Name', h($lead['name'])],
        ['Email', '<a href="mailto:' . h($lead['email']) . '" style="color:#C4113A;">' . h($lead['email']) . '</a>'],
        ['Phone', '<a href="tel:' . h((string) preg_replace('/[^0-9+]/', '', $lead['phone'])) . '" style="color:#C4113A;">' . h($lead['phone']) . '</a>'],
        ['Website', $website],
    ];
    if ($forAdmin) {
        $rows[] = ['Landing page', h($lead['page']) . ($lead['pageUrl'] !== '' ? ' &middot; <a href="' . h($lead['pageUrl']) . '" style="color:#C4113A;">open</a>' : '')];
        $rows[] = ['Submitted', h($lead['time'])];
        if ($lead['ip'] !== '') {
            $rows[] = ['IP address', h($lead['ip'])];
        }
    }
    return detail_rows($rows);
}

function heading(string $text): string
{
    return '<h2 style="margin:24px 0 10px;font-size:16px;line-height:1.3;color:#0A1F44;">' . h($text) . '</h2>';
}

function button(string $url, string $label): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0 4px;"><tr>'
        . '<td style="border-radius:6px;background:#C4113A;">'
        . '<a href="' . h($url) . '" style="display:inline-block;padding:14px 26px;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;">' . h($label) . '</a>'
        . '</td></tr></table>';
}

function admin_html(array $lead, array $result, array $qa): string
{
    $content = '<h1 style="margin:0 0 6px;font-size:22px;line-height:1.3;color:#0A1F44;">New 30-second quiz lead</h1>'
        . '<p style="margin:0 0 16px;font-size:15px;line-height:1.5;color:#3A4558;">' . h($lead['name'])
        . ' completed the quiz on the <strong>' . h($lead['page']) . '</strong> landing page. Reply to this email to contact them directly.</p>'
        . heading('Contact details') . lead_rows($lead, true)
        . heading('Quiz result') . result_block($result)
        . heading('Answers') . answer_rows($qa);
    return email_layout('New quiz lead', $content);
}

function client_html(array $lead, array $result, array $qa): string
{
    $first   = explode(' ', $lead['name'])[0];
    $content = '<h1 style="margin:0 0 6px;font-size:22px;line-height:1.3;color:#0A1F44;">Hi ' . h($first) . ', here is your quiz result</h1>'
        . '<p style="margin:0 0 16px;font-size:15px;line-height:1.5;color:#3A4558;">Thanks for taking the 30-second quiz. Based on your answers, this is the most likely gap holding your marketing back.</p>'
        . result_block($result)
        . '<p style="margin:20px 0 0;font-size:15px;line-height:1.5;color:#3A4558;">Want us to look at it with you? The audit call is free. We go through your website, ads and SEO live and show you what to fix first.</p>'
        . button(BOOKING_URL, 'Book My Free Audit Call')
        . heading('Your answers') . answer_rows($qa)
        . heading('Your details') . lead_rows($lead, false)
        . '<p style="margin:20px 0 0;font-size:15px;line-height:1.5;color:#3A4558;">Questions? Just reply to this email or call us at '
        . '<a href="tel:' . h(PHONE_LINK) . '" style="color:#C4113A;">' . h(PHONE_LABEL) . '</a>.</p>';
    return email_layout('Your quiz result', $content);
}

function text_lines(array $lead, array $result, array $qa, bool $forAdmin): string
{
    $lines   = [];
    $lines[] = 'Name: ' . $lead['name'];
    $lines[] = 'Email: ' . $lead['email'];
    $lines[] = 'Phone: ' . $lead['phone'];
    $lines[] = 'Website: ' . ($lead['website'] !== '' ? $lead['website'] : 'Not provided');
    if ($forAdmin) {
        $lines[] = 'Landing page: ' . $lead['page'] . ($lead['pageUrl'] !== '' ? ' (' . $lead['pageUrl'] . ')' : '');
        $lines[] = 'Submitted: ' . $lead['time'];
        if ($lead['ip'] !== '') {
            $lines[] = 'IP address: ' . $lead['ip'];
        }
    }
    $lines[] = '';
    $lines[] = 'QUIZ RESULT - likely gap: ' . $result['tag'];
    $lines[] = $result['title'];
    $lines[] = $result['desc'];
    $lines[] = '';
    $lines[] = "What we'll check on your free audit call:";
    foreach ($result['checks'] as $check) {
        $lines[] = '- ' . $check;
    }
    $lines[] = '';
    $lines[] = 'ANSWERS';
    foreach ($qa as $i => $pair) {
        $lines[] = ($i + 1) . '. ' . $pair[0] . ' ' . $pair[1];
    }
    return implode("\n", $lines);
}

function admin_text(array $lead, array $result, array $qa): string
{
    return "New 30-second quiz lead\n\n" . text_lines($lead, $result, $qa, true) . "\n";
}

function client_text(array $lead, array $result, array $qa): string
{
    $first = explode(' ', $lead['name'])[0];
    return 'Hi ' . $first . ",\n\nThanks for taking the 30-second quiz. Here is your result.\n\n"
        . text_lines($lead, $result, $qa, false)
        . "\n\nBook your free audit call: " . BOOKING_URL
        . "\nQuestions? Reply to this email or call " . PHONE_LABEL . ".\n\nPNC Logos, Orlando, FL\n" . SITE_URL . "\n";
}
