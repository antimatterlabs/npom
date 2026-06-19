<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    npom_require_post();

    $data = npom_request_data();
    npom_check_honeypot($data);

    $name = npom_string($data, 'name', 160);
    $email = npom_email($data);
    $interest = npom_string($data, 'interest', 60);
    $message = npom_string($data, 'message', 3000);

    if (mb_strlen($name) < 2) {
        npom_error_response('Please enter your name.', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        npom_error_response('Please enter a valid email address.', 422);
    }

    if (!in_array($interest, NPOM_INTERESTS, true)) {
        npom_error_response('Please choose a valid interest option.', 422);
    }

    if ($message === '') {
        npom_error_response('Please include a short message.', 422);
    }

    $pdo = npom_db();
    $contactId = npom_insert_contact($pdo, [
        'name' => $name,
        'email' => $email,
        'interest' => $interest,
        'message' => $message,
    ]);

    if ($interest === 'email-updates') {
        $subscriberId = npom_upsert_subscriber($pdo, $email, 'contact-form');
        $subscribe = npom_mailgun_subscribe($email);
        npom_update_subscriber_mailgun(
            $pdo,
            $subscriberId,
            npom_mailgun_status($subscribe),
            npom_mailgun_response_summary($subscribe)
        );
    }

    $interestLabel = $interest !== '' ? $interest : 'Not selected';
    $notify = npom_mailgun_notify(
        'New NPOM website message',
        "A new message was submitted on the NPOM website.\n\n"
        . "Name: {$name}\n"
        . "Email: {$email}\n"
        . "Interest: {$interestLabel}\n\n"
        . "Message:\n{$message}\n\n"
        . 'Stored locally in contact_messages ID: ' . $contactId,
        $email
    );

    npom_update_contact_mailgun(
        $pdo,
        $contactId,
        npom_mailgun_status($notify),
        npom_mailgun_response_summary($notify)
    );

    npom_json_response([
        'ok' => true,
        'message' => 'Thank you. We will be in touch soon.',
    ]);
} catch (Throwable $error) {
    npom_error_response('We could not send your message right now. Please try again.', 500, $error);
}
