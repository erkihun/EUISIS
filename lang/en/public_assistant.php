<?php

declare(strict_types=1);

/*
 * Addis One Help — public-site assistant strings (docs/public-assistant/architecture.md).
 * Approved public guidance only; the Amharic file mirrors every key.
 */
return [
    'title' => 'Addis One Help',
    'welcome' => 'Hello, I can help you use Addis One public services.',
    'disclaimer' => 'This is general guidance from published information; the institution\'s published instructions and its officers are the authority.',

    'quick_actions' => [
        'how_to_use' => 'How to use this page',
        'submit_feedback' => 'Submit feedback',
        'submit_suggestion' => 'Send a tip-off',
        'submit_complaint' => 'Submit a complaint',
        'track_submission' => 'Track my submission',
        'verify_employee' => 'Verify an employee ID',
        'organization_services' => 'Organization services',
        'contact' => 'Contact information',
        'public_help' => 'Public help',
    ],

    'navigation' => [
        'home' => 'Home',
        'services' => 'Services',
        'announcements' => 'Announcements',
        'support' => 'Support',
        'verify' => 'Verify an ID',
        'id_checker' => 'ID checker',
    ],

    'pages' => [
        'home' => [
            'label' => 'Public home',
            'purpose' => 'This is the public site of Addis One, the city administration\'s employee information and service system. From here you can read announcements, browse published services, verify an employee ID card and reach support.',
            'steps' => ['Open Services for published service information.', 'Open Announcements for current notices.', 'Scan an institution\'s or employee\'s feedback QR code to send feedback, a tip-off or a complaint.'],
            'notes' => ['Signing in is for staff accounts only; citizens do not need an account.'],
        ],
        'services' => [
            'label' => 'Services',
            'purpose' => 'Published public services with their purpose, requirements and steps.',
            'steps' => ['Search by name.', 'Open a service to read its requirements and steps.', 'Follow the published steps at the service point named there.'],
            'notes' => ['Only published services appear; if a service is missing, ask the institution directly.'],
        ],
        'service' => [
            'label' => 'Service details',
            'purpose' => 'The published description, eligibility, requirements and steps of one service.',
            'steps' => ['Read the requirements before visiting.', 'Use the published contact or action link if one is shown.'],
            'notes' => [],
        ],
        'announcements' => [
            'label' => 'Announcements',
            'purpose' => 'Published notices and vacancy announcements of the city administration.',
            'steps' => ['Open an announcement to read it in full.', 'Transfer announcements have their own application page for eligible staff.'],
            'notes' => [],
        ],
        'support' => [
            'label' => 'Support',
            'purpose' => 'Published contact details, office hours and frequently asked questions.',
            'steps' => ['Check the FAQs first.', 'Use the published phone or e-mail for anything else.'],
            'notes' => [],
        ],
        'verify' => [
            'label' => 'Verify an ID',
            'purpose' => 'Checks whether an employee ID card is genuine and active.',
            'steps' => ['Scan the QR code on the card, or open the ID checker and enter the card number.', 'Confirm the one-time code sent to the registered contact when the page asks for it.', 'Read the verification result on the page.'],
            'notes' => ['The result shows only the approved minimum identity; no other details are available through this site.'],
        ],
        'id_checker' => [
            'label' => 'ID checker',
            'purpose' => 'Verifies an employee ID card with a one-time code.',
            'steps' => ['Enter the card number or scan the card\'s QR code.', 'Request the one-time code and enter it.', 'Read the result.'],
            'notes' => ['The code is sent to the contact registered for the card, not to you.', 'Only the approved identity fields are shown.'],
        ],
        'employee_qr' => [
            'label' => 'Employee feedback',
            'purpose' => 'You scanned an employee\'s feedback code. You can send feedback about the service you received, a tip-off, or a complaint.',
            'steps' => ['Choose Feedback, Tip-off or Complaint.', 'Fill in the short form; describe what happened and when.', 'Press Send and keep the reference shown on the receipt.'],
            'notes' => ['Submissions go to the institution\'s responsible office, not to the employee.'],
        ],
        'organization_qr' => [
            'label' => 'Organization feedback',
            'purpose' => 'You scanned an institution\'s feedback code. You can send feedback, a tip-off or a complaint about its services.',
            'steps' => ['Choose Feedback, Tip-off or Complaint.', 'Select the service or office if the form asks for it.', 'Describe what happened and press Send; keep the reference on the receipt.'],
            'notes' => [],
        ],
        'feedback_form' => [
            'label' => 'Submission form',
            'purpose' => 'This form sends your submission to the institution\'s responsible office.',
            'steps' => ['Describe what happened, where and when, in your own words.', 'Add a photo only if the form allows it and it helps.', 'Press Send once; the receipt shows your reference.'],
            'notes' => ['Contact details are optional unless the form marks them as required.'],
        ],
        'unknown' => [
            'label' => 'Addis One',
            'purpose' => 'I can help with the public site: services, announcements, feedback, ID verification and support.',
            'steps' => ['Use the quick actions below or ask a question.'],
            'notes' => [],
        ],
    ],

    'feedback' => [
        'feedback' => [
            'title' => 'Feedback',
            'what' => 'Tells the institution how a service went — good or bad — so it can improve.',
            'steps' => ['Rate the service if asked.', 'Write what went well or badly.', 'Send; you will receive a reference.'],
        ],
        'suggestion' => [
            'title' => 'Tip-off',
            'what' => 'Reports something the institution should look into, such as an irregularity or a missing service.',
            'steps' => ['Describe what you observed, where and when.', 'Add evidence only if the form allows it.', 'Send; you will receive a reference.'],
        ],
        'complaint' => [
            'title' => 'Complaint',
            'what' => 'A public service complaint: a service was refused, delayed or handled wrongly.',
            'steps' => ['State the service and what went wrong.', 'Give the date and place.', 'Send; you will receive a reference.'],
        ],
        'boundary' => 'A public complaint is a service-delivery channel. It is not a disciplinary case, an employee grievance or an appeal, and it does not by itself establish wrongdoing; the institution reviews it.',
        'scan_hint' => 'To send one, scan the feedback QR code of the institution or employee concerned.',
    ],

    'tracking' => [
        'what' => 'When you send a submission, the receipt shows a reference number. Keep it: it identifies your submission to the institution.',
        'steps' => ['Note the reference from the receipt.', 'Quote it when you contact the institution about your submission.'],
        'no_lookup' => 'This site does not offer a status lookup by reference, and I cannot look submissions up for you. The institution\'s published contact can tell you about your own submission.',
    ],

    'status' => [
        'available' => 'Service available: the public site is working normally.',
        'degraded' => 'Service degraded: some parts of the site may be slow. Your submissions still work.',
        'unavailable' => 'Temporarily unavailable: part of the service is not working right now. Please try again later.',
    ],

    'answers' => [
        'knowledge_empty' => 'I could not find an approved public instruction covering that question. The Support page lists the published contacts.',
        'faq_source' => 'Frequently asked questions',
        'services_empty' => 'No published service matches that. Browse the services list or ask the institution directly.',
        'services_intro' => 'Published services that match:',
        'requirements' => 'Requirements',
        'no_organization' => 'Scan an institution\'s feedback QR code and I can tell you its public name, published services and contact.',
        'organization_intro' => 'You scanned :name.',
        'organization_services_many' => 'This institution publishes many services; the form lets you search them.',
        'organization_services_none' => 'This institution has not published a service list here.',
        'phone' => 'Phone',
        'contact_unavailable' => 'No public contact details are published here.',
        'navigation' => 'Here is where you can go:',
        'support_intro' => 'For anything I cannot answer, use the published support contacts:',
        'denied' => 'I can only help with public information: how to use this site, published services and contacts, feedback and complaints, and ID verification. I do not have access to employee details, submissions, internal records or systems.',
        'target_organization' => 'Current page: :name.',
        'target_employee' => 'Current page: feedback for :name.',
    ],

    'errors' => [
        'assistant_disabled' => 'Help is not available right now.',
        'rate_limited' => 'Too many messages in a short time. Please wait a moment.',
        'unavailable' => 'Help is temporarily unavailable.',
    ],
];
