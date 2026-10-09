const exports = {
    export: 'ላክ',
    exportCsv: 'CSV ላክ',
    exportExcel: 'Excel ላክ',
    exportPdf: 'PDF ላክ',
    exportPng: 'PNG ላክ',
    print: 'አትም',
    printCard: 'ካርድ አትም',
    download: 'አውርድ',
    generating: 'በመፍጠር ላይ…',
    exportSuccess: 'ወደ ውጭ መላኩ ተጠናቋል።',
    exportError: 'ወደ ውጭ መላኩ አልተሳካም። እባክዎ እንደገና ይሞክሩ።',
    // Column headers shared across reports
    no: 'ቁጥር',
    name: 'ስም',
    employeeNumber: 'የሠራተኛ ቁጥር',
    organization: 'ተቋም',
    position: 'ቦታ',
    status: 'ሁኔታ',
    date: 'ቀን',
    amount: 'መጠን',
    total: 'ድምር',
    generatedAt: 'የተፈጠረበት ቀን',
    generatedBy: 'የተፈጠረው በ',
    // PDF headers
    reportTitle: 'ሪፖርት',
    pageOf: 'ገጽ :current ከ :total',
    confidential: 'ሚስጥራዊ',
    officialDocument: 'ይፋዊ ሰነድ',
} as const;

export default exports;
