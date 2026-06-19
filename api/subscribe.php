<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    npom_require_post();

    $data = npom_request_data();
    npom_check_honeypot($data);

    $email = npom_email($data);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        npom_error_response('Please enter a valid email address.', 422);
    }

    $pdo = npom_db();
    npom_apply_spam_protection($pdo, $data, 'subscribe');

    if (npom_subscriber_exists($pdo, $email)) {
        npom_error_response('That email is already on the list.', 409);
    }

    $subscriberId = npom_upsert_subscriber($pdo, $email, 'footer');

    $mailgun = npom_mailgun_subscribe($email);
    npom_update_subscriber_mailgun(
        $pdo,
        $subscriberId,
        npom_mailgun_status($mailgun),
        npom_mailgun_response_summary($mailgun)
    );

    $config = npom_config()['mailgun'] ?? [];
    if ((bool) ($config['notify_on_subscribe'] ?? false)) {
        npom_mailgun_notify(
            'New NPOM email list signup',
            "A new email address joined the NPOM updates list.\n\nEmail: {$email}",
            $email
        );
    }

    npom_json_response([
        'ok' => true,
        'message' => 'Thanks, you are on the list.',
    ]);
} catch (Throwable $error) {
    npom_error_response('We could not save your email right now. Please try again.', 500, $error);
}
