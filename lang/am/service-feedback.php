<?php

declare(strict_types=1);

/*
 * የሕዝብ የደንበኛ አገልግሎት ግብረመልስ — ማንነቱ ያልታወቀ ጎብኚ የሚያነባቸው መልዕክቶች።
 * ከ lang/en/service-feedback.php ጋር ይመሳሰላል።
 */
return [
    'service_not_provided' => 'ይህ አገልግሎት በዚህ ባለሙያ አይሰጥም።',
    'form_replayed' => 'ይህ ቅጽ አስቀድሞ ገብቷል። ሌላ ምላሽ ለመላክ ገጹን እንደገና ይክፈቱ።',

    // የተቋም የአስተያየት QR አስተዳደር
    'qrGenerated' => 'የተቋሙ የአስተያየት QR ነቅቷል።',
    'qrDisabled' => 'የተቋሙ የአስተያየት QR ታግዷል፤ ስካኖች የማይገኝ ገጽን ያሳያሉ።',
    'qrEnabled' => 'የተቋሙ የአስተያየት QR እንደገና ነቅቷል።',
    'qrRotated' => 'አዲስ የተቋም የአስተያየት QR ተሰጥቷል፤ የቀድሞው ኮድ ተሰርዟል።',
    'qrRevoked' => 'የተቋሙ የአስተያየት QR ተሰርዟል።',

    'validation' => [
        'service_required' => 'እባክዎ ያገኙትን አገልግሎት ይምረጡ።',
        'rating_required' => 'እባክዎ የእርካታ ደረጃ ይምረጡ።',
        'rating_range' => 'እባክዎ ከ1 እስከ 5 ኮከብ ያለ ደረጃ ይምረጡ።',
        'message_required' => 'እባክዎ ጥቆማዎን ወይም ቅሬታዎን ይግለጹ።',
        'attachments_max' => 'እስከ 3 ፋይሎች ብቻ ማያያዝ ይችላሉ።',
        'attachment_type' => 'PDF፣ JPEG እና PNG ፋይሎች ብቻ ይቀበላሉ።',
        'attachment_size' => 'እያንዳንዱ ፋይል 5 MB ወይም ከዚያ በታች መሆን አለበት።',
        'incident_date_future' => 'የክስተቱ ቀን ወደፊት ሊሆን አይችልም።',
    ],
];
