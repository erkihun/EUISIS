<?php

// Core notification templates (ADR 0011). :number is the record's number or code; no personal details.
return [
    'events' => [
        'employee_submitted' => ['title' => 'ሠራተኛ :number ማረጋገጫ በመጠባበቅ ላይ ነው', 'body' => 'ሠራተኛ :number ለማረጋገጫ ቀርቧል። ለመፈተሽ መዝገቡን ይክፈቱ።'],
        'employee_returned' => ['title' => 'ሠራተኛ :number ተመልሷል', 'body' => 'ሠራተኛ :number ለእርማት ተመልሷል። ምክንያቱን ለማየት መዝገቡን ይክፈቱ።'],
        'employee_verified' => ['title' => 'ሠራተኛ :number ማጽደቅ በመጠባበቅ ላይ ነው', 'body' => 'ሠራተኛ :number ተረጋግጧል፤ አሁን በሌላ ሰው መጽደቅ አለበት።'],
        'employee_approved' => ['title' => 'ሠራተኛ :number ጸድቋል', 'body' => 'ሠራተኛ :number ጸድቋል፤ የመጀመሪያ ምደባው በሥራ ላይ ውሏል።'],
        'hierarchy_submitted' => ['title' => 'ተዋረድ :number ማጽደቅ በመጠባበቅ ላይ ነው', 'body' => 'የተዋረድ ስሪት :number ለግምገማና ለማጽደቅ ቀርቧል።'],
        'hierarchy_approved' => ['title' => 'ተዋረድ :number ለማተም ዝግጁ ነው', 'body' => 'የተዋረድ ስሪት :number ጸድቋል፤ ሊታተም ይችላል።'],
        'hierarchy_published' => ['title' => 'ተዋረድ :number በሥራ ላይ ውሏል', 'body' => 'የተዋረድ ስሪት :number ታትሞ አሁን በሥራ ላይ ያለው መዋቅር ሆኗል።'],
        'card_approved' => ['title' => 'ለ:number የቀረበው የካርድ ጥያቄ ጸድቋል', 'body' => 'ለሠራተኛ :number የቀረበው የካርድ ጥያቄ ጸድቋል፤ ካርዱ ሕትመት በመጠባበቅ ላይ ነው።'],
        'card_printed' => ['title' => 'ካርድ :number የጥራት ፍተሻ ያስፈልገዋል', 'body' => 'ካርድ :number ታትሟል። ከመሰጠቱ በፊት ከአታሚው ውጭ ያለ ሰው ይፈትሸዋል።'],
        'card_quality_failed' => ['title' => 'ካርድ :number የጥራት ፍተሻውን አላለፈም', 'body' => 'ካርድ :number የጥራት ፍተሻውን አላለፈም። ድጋሚ ሕትመት ያጽድቁ ወይም ይሰርዙት።'],
        'card_ready_for_pickup' => ['title' => 'ካርድ :number ዝግጁ ነው', 'body' => 'ካርድ :number የጥራት ፍተሻውን አልፏል፤ ሊሰጥ ይችላል።'],
        'card_activated' => ['title' => 'ካርድ :number ንቁ ሆኗል', 'body' => 'ካርድ :number ተሰጥቶ ንቁ ሆኗል።'],
        'card_expiring' => ['title' => 'ካርድ :number በቅርቡ ጊዜው ያልፋል', 'body' => 'ካርድ :number ጊዜው :until ያልፋል። እድሳት በጊዜ ይጠይቁ።'],
        'card_suspended' => ['title' => 'ካርድ :number ታግዷል', 'body' => 'ካርድ :number ታግዷል፤ እስኪመለስ ድረስ አያረጋግጥም።'],
        'card_revoked' => ['title' => 'ካርድ :number ተሰርዟል', 'body' => 'ካርድ :number ተሰርዟል፤ ካርዱም ሆነ የማረጋገጫ መለያው ከእንግዲህ አያረጋግጡም።'],
        'api_credential_rotated' => ['title' => 'የ:number የAPI ሚስጥር ተተክቷል', 'body' => 'የተገናኘው መተግበሪያ :number ሚስጥር ተተክቷል። የቀድሞው ሚስጥር ከእንግዲህ አይሠራም።'],
        'api_client_revoked' => ['title' => 'የAPI ደንበኛ :number ተሰርዟል', 'body' => 'የተገናኘው መተግበሪያ :number ተሰርዟል፤ ከእንግዲህ ዋና መዝገቡን መጠየቅ አይችልም።'],
        'webhook_suspended' => ['title' => 'ዌብሁክ :number ታግዷል', 'body' => 'ዌብሁክ :number በተደጋጋሚ መልእክት ባለመድረሱ ታግዷል። ክስተቶቹ እስኪመለስ ድረስ ይጠባበቃሉ።'],
        'break_glass_requested' => ['title' => 'የአደጋ ጊዜ መዳረሻ ተጠይቋል', 'body' => 'አንድ ሰው የአደጋ ጊዜ መዳረሻ ጠይቋል። ለማጽደቅ ወይም ውድቅ ለማድረግ የደህንነት ማዕከሉን ይክፈቱ።'],
        'break_glass_approved' => ['title' => 'የአደጋ ጊዜ መዳረሻዎ ጸድቋል', 'body' => 'የአደጋ ጊዜ መዳረሻዎ በራሱ እስኪቋረጥ ድረስ በሥራ ላይ ነው። በእሱ ስር የሚያደርጉት ሁሉ በኦዲት መዝገቡ ላይ ምልክት ይደረግበታል።'],
        'break_glass_expiring' => ['title' => 'የአደጋ ጊዜ መዳረሻዎ በቅርቡ ያበቃል', 'body' => 'የአደጋ ጊዜ መዳረሻዎ :until ያበቃል።'],
        'break_glass_expired' => ['title' => 'የአደጋ ጊዜ መዳረሻዎ አብቅቷል', 'body' => 'የአደጋ ጊዜ መዳረሻዎ አብቅቷል።'],
        'import_completed' => ['title' => 'ገቢ ፋይሉ ተጠናቋል', 'body' => 'የ:number ገቢ ፋይልዎ ተጠናቋል። ውጤቱን ለማየት ይክፈቱት።'],
        'import_failed' => ['title' => 'ገቢ ፋይሉ አልተሳካም', 'body' => 'የ:number ገቢ ፋይልዎ አልተሳካም። ምክንያቱን ለማየት ይክፈቱት።'],
        'data_quality_critical' => ['title' => 'አሳሳቢ የመረጃ ጥራት ችግር ተገኝቷል', 'body' => 'ፍተሻው አሳሳቢ ችግሮችን (:check) አግኝቷል። ለመከታተል የመረጃ ጥራት ገጹን ይክፈቱ።'],
        'security_alert' => ['title' => 'የደህንነት ማስጠንቀቂያ', 'body' => 'ትኩረት የሚፈልግ የደህንነት ክስተት አለ፤ የደህንነት ማዕከሉን ይክፈቱ።'],
    ],
];
