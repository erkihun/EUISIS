const enCalendar = {
    gregorian: 'Gregorian',
    ethiopian: 'Ethiopian',
    calendarSystem: 'Calendar System',
    selectDate: 'Select date',
    selectDateRange: 'Select date range',
    startDate: 'Start date',
    endDate: 'End date',
    today: 'Today',
    clear: 'Clear',
    months: {
        1: 'January', 2: 'February', 3: 'March', 4: 'April',
        5: 'May', 6: 'June', 7: 'July', 8: 'August',
        9: 'September', 10: 'October', 11: 'November', 12: 'December',
    },
    monthsEth: {
        1: 'Meskerem', 2: 'Tikimt', 3: 'Hidar', 4: 'Tahsas',
        5: 'Tir', 6: 'Yekatit', 7: 'Megabit', 8: 'Miyazia',
        9: 'Ginbot', 10: 'Sene', 11: 'Hamle', 12: 'Nehase', 13: 'Pagume',
    },
    weekdays: {
        short: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
        long: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
    },
    invalidDate: 'Invalid date',
    dateFormat: 'YYYY-MM-DD',
} as const;

export default enCalendar;
