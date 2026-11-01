<?php

// የሠራተኛ መለያዎችና የEUISIS ሠራተኞቻቸው (docs/euisis-integration.md, "Staff accounts")።
return [
    'title' => 'የEUISIS ሠራተኞች',
    'subtitle' => 'በEUISIS ያሉ የፍርድ ቤቱ የሥራ ክፍሎች ሠራተኞችና የሠራተኛ መለያዎቻቸው። የፍርድ ቤት ሚናዎች እዚህ ይሰጣሉ፤ ስም፣ የሥራ መደብና የሥራ ክፍል ከEUISIS ይመጣሉ።',
    'roster_link' => 'የEUISIS ሠራተኞች',

    'units' => [
        'title' => 'የፍርድ ቤቱ የሥራ ክፍሎች',
        'help' => 'ሠራተኞቻቸው የፍርድ ቤቱ ሠራተኞች የሆኑ የEUISIS የሥራ ክፍሎች፤ ከEUISIS ከተቀዱት የሥራ ክፍሎች ይመረጣሉ።',
        'none' => 'እስካሁን የተመረጠ የፍርድ ቤት የሥራ ክፍል የለም።',
        'organization' => 'ተቋም',
        'choose_organization' => 'ተቋም ይምረጡ',
        'unit' => 'የሥራ ክፍል',
        'choose_unit' => 'የሥራ ክፍል ይምረጡ',
        'include_sub_units' => 'ከንዑስ የሥራ ክፍሎቹ ጋር',
        'only_this_unit' => 'ይህ የሥራ ክፍል ብቻ',
        'add' => 'የሥራ ክፍል ጨምር',
        'remove' => 'አስወግድ',
        'save' => 'የሥራ ክፍሎቹን አስቀምጥ',
        'read_only' => 'የፍርድ ቤቱን የሥራ ክፍሎች መቀየር የሚችሉት የሥርዓቱን ቅንብሮች የሚያስተዳድሩ ብቻ ናቸው።',
        'no_units_mirrored' => 'የዚህ ተቋም የሥራ ክፍሎች እስካሁን አልተቀዱም፤ php artisan euisis:sync ያስኪዱ።',
    ],

    'roster' => [
        'title' => 'የፍርድ ቤቱ የሥራ ክፍሎች ሠራተኞች',
        'employee_number' => 'የሠራተኛ ቁጥር',
        'name' => 'ስም',
        'position' => 'የሥራ መደብ',
        'unit' => 'የሥራ ክፍል',
        'status' => 'በEUISIS ያለው ሁኔታ',
        'account' => 'የሠራተኛ መለያ',
        'not_linked' => 'መለያ የለውም',
        'suspended' => 'ታግዷል',
        'create_account' => 'መለያ ክፈት',
        'open_account' => 'መለያውን ክፈት',
        'empty' => 'EUISIS በፍርድ ቤቱ የሥራ ክፍሎች ውስጥ ሠራተኛ አይዘረዝርም።',
        'choose_units_first' => 'ሠራተኞቻቸውን ለማየት የፍርድ ቤቱን የሥራ ክፍሎች ይምረጡ።',
        'disabled' => 'ከEUISIS ጋር ያለው ግንኙነት ጠፍቷል (EUISIS_ENABLED፣ EUISIS_URL እና EUISIS_TOKEN)።',
        'skipped_units' => 'እነዚህ የሥራ ክፍሎች በEUISIS ኮድ ስለሌላቸው ሊዘረዘሩ አይችሉም፦ :units',
        'summary' => 'ከ:total ሠራተኞች :linked የሠራተኛ መለያ አላቸው።',
    ],

    'notices' => [
        'title' => 'የቅርብ ጊዜ የመለያ ለውጦች',
        'none' => 'እስካሁን የታገደ ወይም የተመለሰ የሠራተኛ መለያ የለም።',
        'suspended' => 'ታግዷል',
        'restored' => 'ተመልሷል',
    ],

    'panel' => [
        'title' => 'የEUISIS ሠራተኛ',
        'not_linked' => 'ይህ መለያ ከEUISIS ሠራተኛ ጋር አልተገናኘም።',
        'disabled' => 'ከEUISIS ጋር ያለው ግንኙነት ጠፍቷል።',
        'employee_number' => 'የሠራተኛ ቁጥር',
        'name' => 'በEUISIS ያለው ስም',
        'position' => 'የሥራ መደብ',
        'unit' => 'የሥራ ክፍል',
        'organization' => 'ተቋም',
        'status' => 'በEUISIS ያለው ሁኔታ',
        'linked_at' => 'የተገናኘበት',
        'synced_at' => 'ከEUISIS ለመጨረሻ ጊዜ የተነበበበት',
        'outside_court' => 'EUISIS ይህንን ሠራተኛ ከእንግዲህ በፍርድ ቤቱ የሥራ ክፍሎች ውስጥ አያስቀምጠውም።',
        'suspended' => 'በEUISIS የሠራተኛ ማረጋገጫ ታግዷል፦ :reason',
        'link' => 'አገናኝ',
        'link_help' => 'የሠራተኛው የEUISIS ቁጥር። ማገናኘት የሚቻለው በፍርድ ቤቱ የሥራ ክፍሎች ውስጥ ያለን ንቁ ሠራተኛ ብቻ ነው።',
        'unlink' => 'ግንኙነቱን አቋርጥ',
        'unlink_confirm' => 'የዚህን መለያ ከEUISIS ሠራተኛው ጋር ያለውን ግንኙነት ያቋርጡ? እገዳ ካለ መለያው ንቁ እስኪደረግ ድረስ ይቆያል።',
        'read_only' => 'ስም፣ የሥራ መደብና የሥራ ክፍል ከEUISIS ይመጣሉ፤ የሚቀየሩትም እዚያ ነው።',
    ],

    'create' => [
        'from_euisis' => 'ከEUISIS፦ :name፣ የሠራተኛ ቁጥር :number። ኢሜይሉንና የፍርድ ቤት ሚናዎቹን ያክሉ።',
        'employee_number' => 'የEUISIS የሠራተኛ ቁጥር',
    ],

    'statuses' => [
        'active' => 'ንቁ',
        'draft' => 'ረቂቅ',
        'suspended' => 'የታገደ',
        'transferred' => 'የተዛወረ',
        'retired' => 'ጡረታ የወጣ',
        'terminated' => 'ከሥራ የተሰናበተ',
        'deceased' => 'ያረፈ',
    ],

    'reasons' => [
        'left_unit' => 'ሠራተኛው በEUISIS ከእንግዲህ በፍርድ ቤቱ የሥራ ክፍሎች ውስጥ አልተመደበም።',
        'not_active' => 'ሠራተኛው በEUISIS ከእንግዲህ ንቁ አይደለም።',
        'not_in_euisis' => 'ይህ ሠራተኛ ከእንግዲህ በEUISIS የለም።',
    ],

    'notice' => [
        'suspended' => 'የሠራተኛ መለያ ታግዷል፦ :name',
        'restored' => 'የሠራተኛ መለያ ተመልሷል፦ :name',
        'restored_detail' => 'ሠራተኛው በEUISIS ወደ ፍርድ ቤቱ የሥራ ክፍሎች ተመልሷል።',
    ],

    'errors' => [
        'no_court_unit' => 'መጀመሪያ የፍርድ ቤቱን የሥራ ክፍሎች ይምረጡ (ተጠቃሚዎች > የEUISIS ሠራተኞች)።',
        'not_found' => 'EUISIS ይህ ቁጥር ያለው ሠራተኛ የለውም።',
        'ambiguous' => 'ይህ ቁጥር ያላቸው ከአንድ በላይ የEUISIS ሠራተኞች አሉ።',
        'not_active' => 'ይህ ሠራተኛ በEUISIS ንቁ አይደለም።',
        'outside_court' => 'ይህ ሠራተኛ በEUISIS በፍርድ ቤቱ የሥራ ክፍሎች ውስጥ አልተመደበም።',
        'linked_to_another_account' => 'ይህ ሠራተኛ ቀድሞውኑ ከሌላ የሠራተኛ መለያ ጋር ተገናኝቷል።',
        'euisis_unavailable' => 'EUISIS ሊገኝ አልቻለም። ቆይተው እንደገና ይሞክሩ።',
    ],

    'flash' => [
        'units_saved' => 'የፍርድ ቤቱ የሥራ ክፍሎች ተቀምጠዋል። የተገናኙ መለያዎች በቀጣዩ የEUISIS ማመሳሰል ይረጋገጣሉ።',
        'linked' => 'መለያው ከEUISIS ሠራተኛው ጋር ተገናኝቷል።',
        'unlinked' => 'መለያው ከእንግዲህ ከEUISIS ጋር አልተገናኘም።',
    ],

    'sign_in_suspended' => 'ይህ የሠራተኛ መለያ ታግዷል፦ ሠራተኛው በEUISIS ከእንግዲህ በፍርድ ቤቱ የሥራ ክፍሎች ውስጥ የለም። አስተዳዳሪን ይጠይቁ።',
];
