const amCalendar = {
    gregorian: 'ጎርጎሮሳዊ',
    ethiopian: 'ኢትዮጵያዊ',
    calendarSystem: 'የቀን አቆጣጠር',
    selectDate: 'ቀን ይምረጡ',
    selectDateRange: 'የቀን ክልል ይምረጡ',
    startDate: 'መጀመሪያ ቀን',
    endDate: 'መጨረሻ ቀን',
    today: 'ዛሬ',
    clear: 'አጥፋ',
    months: {
        1: 'ጃኑዋሪ', 2: 'ፌብሩዋሪ', 3: 'ማርች', 4: 'ኤፕሪል',
        5: 'ሜይ', 6: 'ጁን', 7: 'ጁላይ', 8: 'ኦገስት',
        9: 'ሴፕቴምበር', 10: 'ኦክቶበር', 11: 'ኖቬምበር', 12: 'ዲሴምበር',
    },
    monthsEth: {
        1: 'መስከረም', 2: 'ጥቅምት', 3: 'ኅዳር', 4: 'ታኅሣሥ',
        5: 'ጥር', 6: 'የካቲት', 7: 'መጋቢት', 8: 'ሚያዝያ',
        9: 'ግንቦት', 10: 'ሰኔ', 11: 'ሐምሌ', 12: 'ነሐሴ', 13: 'ጳጉሜ',
    },
    weekdays: {
        short: ['እሁ', 'ሰኞ', 'ማክ', 'ረቡ', 'ሐሙ', 'ዓርብ', 'ቅዳ'],
        long: ['እሁድ', 'ሰኞ', 'ማክሰኞ', 'ረቡዕ', 'ሐሙስ', 'ዓርብ', 'ቅዳሜ'],
    },
    invalidDate: 'ትክክለኛ ቀን አይደለም',
    dateFormat: 'YYYY-MM-DD',
} as const;

export default amCalendar;
