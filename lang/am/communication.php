<?php

declare(strict_types=1);

// ኮሙኒኬሽንና ትብብር — የአገልጋይ መልዕክቶች (docs/communication-module.md)።
// የገጽ ጽሑፍ በ resources/js/i18n/{en,am}/communication.ts ውስጥ ነው።
return [
    'errors' => [
        'recipient_unavailable' => 'ከዚህ ሰው ጋር ውይይት መጀመር አይችሉም።',
        'empty_message' => 'መልዕክት ይጻፉ ወይም ፋይል ያያይዙ።',
        'parent_invalid' => 'መመለስ የሚችሉት በዚህ ውይይት ውስጥ ላለ መልዕክት ብቻ ነው።',
        'broadcast_mention_forbidden' => 'ሁሉንም መጥቀስ የሚችሉት የቡድን ወይም የቻናል አስተዳዳሪዎች ብቻ ናቸው።',
        'attachment_invalid' => 'ከፋይሎቹ አንዱ አይገኝም። እንደገና ይጫኑት።',
        'type_not_allowed' => 'ይህን ዓይነት ፋይል ማያያዝ አይቻልም።',
        'type_mismatch' => 'የፋይሉ ይዘት ከዓይነቱ ጋር አይዛመድም።',
        'too_large' => 'ፋይሉ ከ:max KB ይበልጣል።',
        'reaction_invalid' => 'ከቀረቡት ምላሾች አንዱን ይምረጡ።',
        'message_deleted' => 'ይህ መልዕክት ተሰርዟል።',
        'too_many_pins' => 'ይህ ውይይት የተፈቀደውን ከፍተኛ የተሰኩ መልዕክቶች ብዛት ደርሷል።',
        'forward_targets' => 'ከ1 እስከ :max ውይይቶችን ይምረጡ።',
        'groups_disabled' => 'ቡድን መፍጠር ተዘግቷል።',
        'member_unavailable' => 'ከእነዚህ ሰዎች አንዱን መጨመር አይቻልም።',
        'owner_must_stay' => 'ከመውጣትዎ በፊት ባለቤትነትን ለሌላ አባል ያስተላልፉ፣ ወይም ቡድኑን ወደ ማኅደር ያስገቡ።',
        'last_owner' => 'ቡድን ቢያንስ አንድ ባለቤት ሊኖረው ይገባል።',
        'channel_slug_taken' => 'በዚህ ስም ቻናል እዚህ አስቀድሞ አለ።',
        'channel_not_joinable' => 'ይህን ቻናል መቀላቀል አይቻልም።',
        'announcement_targets_required' => 'ይህን ማስታወቂያ ማን እንደሚቀበል ይምረጡ።',
        'announcement_target_invalid' => 'ከተቀባዮቹ አንዱ ትክክል አይደለም ወይም ከወሰንዎ ውጭ ነው።',
        'announcement_not_editable' => 'መቀየር የሚቻለው ረቂቅ ወይም ቀን የተያዘላቸው ማስታወቂያዎችን ብቻ ነው።',
        'publish_at_past' => 'የሚወጣበት ጊዜ ወደፊት መሆን አለበት።',
        'expires_before_publish' => 'የሚያበቃበት ጊዜ ከሚወጣበት ጊዜ በኋላ መሆን አለበት።',
        'already_acknowledged' => 'ይህን ማስታወቂያ አስቀድመው አረጋግጠዋል።',
        'task_assignee_invalid' => 'ተግባሩ ሊመደብ የሚችለው ለዚህ ውይይት አባል ብቻ ነው።',
        'report_duplicate' => 'ይህን አስቀድመው ሪፖርት አድርገዋል።',
        'access_window_invalid' => 'የፈቃዱ ጊዜ ከአሁን ጀምሮ መጀመርና ቢበዛ :days ቀናት መቆየት አለበት።',
        'access_target_required' => 'ፈቃዱ የሚሸፍነውን ውይይት ወይም ሠራተኛ ይግለጹ።',
        'access_not_in_force' => 'ይህ የምርመራ ፈቃድ ንቁ አይደለም።',
        'cannot_block_self' => 'ራስዎን ማገድ አይችሉም።',
    ],

    'labels' => [
        'unknown_person' => 'የቀድሞ አባል',
        'deleted_message' => 'ይህ መልዕክት ተሰርዟል።',
    ],

    'system' => [
        'member_added' => ':actor :subjectን ጨመሩ።',
        'member_removed' => ':actor :subjectን አስወገዱ።',
        'member_left' => ':actor ወጡ።',
        'group_created' => ':actor ቡድኑን ፈጠሩ።',
        'channel_created' => ':actor ቻናሉን ፈጠሩ።',
        'task_created' => ':actor ከመልዕክት ተግባር ፈጠሩ።',
    ],

    'notifications' => [
        'open' => 'ይክፈቱ',
        'generic' => ['title' => 'ኮሙኒኬሽን', 'message' => 'በኮሙኒኬሽን አዲስ እንቅስቃሴ አለዎት።'],
        'direct_message' => ['title' => 'አዲስ መልዕክት', 'message' => ':name መልዕክት ልከውልዎታል።'],
        'mentioned' => ['title' => 'ተጠቅሰዋል', 'message' => ':name በውይይት ውስጥ ጠቅሰውዎታል።'],
        'added_to_group' => ['title' => 'ወደ ቡድን ተጨምረዋል', 'message' => ':name ወደ :group ጨምረውዎታል።'],
        'removed_from_group' => ['title' => 'ከቡድን ተወግደዋል', 'message' => 'ከ:group ተወግደዋል።'],
        'channel_invitation' => ['title' => 'ወደ ቻናል ተጨምረዋል', 'message' => ':name ወደ :channel ቻናል ጨምረውዎታል።'],
        'announcement_important' => ['title' => 'አስፈላጊ ማስታወቂያ', 'message' => ':title'],
        'announcement_urgent' => ['title' => 'አስቸኳይ ማስታወቂያ', 'message' => ':title'],
        'announcement_ack_required' => ['title' => 'እባክዎ ማስታወቂያውን ያረጋግጡ', 'message' => ':title'],
        'task_assigned' => ['title' => 'ተግባር ተመድቦልዎታል', 'message' => ':name «:task» የሚለውን ተግባር መድበውልዎታል።'],
    ],

    'export' => [
        'headers' => ['ሠራተኛ', 'የሠራተኛ ቁጥር', 'ተቋም', 'የደረሰው', 'የታየው', 'የተረጋገጠው'],
    ],

    'flash' => [
        'settings_saved' => 'የኮሙኒኬሽን ቅንብሮች ተቀምጠዋል።',
    ],
];
