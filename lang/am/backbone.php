<?php

declare(strict_types=1);

return [
    // Master backbone commands (docs/architecture/master-backbone.md).
    'transfer_effective_date_after_current' => 'የሚጸናበት ቀን አሁን ያለው ምደባ ከጀመረበት ቀን (:date) በኋላ መሆን አለበት።',
    // Ending and restarting a placement when employment ends or resumes (ADR 0002).
    'assignment_end_before_start' => 'ምደባው ከጀመረበት ቀን (:date) በፊት ሊያበቃ አይችልም።',
    'assignment_no_previous_placement' => 'ይህ ሠራተኛ የሚመለስበት የቀድሞ ምደባ የለውም። በዝውውር ይመድቡት።',
    'assignment_position_inactive' => 'የሠራተኛው የመጨረሻ የሥራ መደብ ንቁ አይደለም። በዝውውር ይመድቡት።',
    'assignment_position_full' => 'የሠራተኛው የመጨረሻ የሥራ መደብ ክፍት የጸደቀ ቦታ የለውም። በዝውውር ይመድቡት።',
    // The master-data freeze used during a cutover or restore window (config/core.php).
    'master_data_frozen' => 'በታቀደ የጥገና ሥራ ወቅት ዋና መዝገቡ ለማንበብ ብቻ ነው። ለውጥዎ አልተቀመጠም፤ የጥገና ጊዜው ካለቀ በኋላ እንደገና ይሞክሩ።',
];
