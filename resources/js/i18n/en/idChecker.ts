/**
 * Public Global ID Checker.
 *
 * Wording is deliberately uniform across failure modes: an anonymous visitor
 * must not be able to tell an unknown card from a revoked one, or a wrong code
 * from a card that never existed.
 */
export default {
    title: 'Global ID Checker',
    subtitle: 'Verify an employee ID card. The card holder must approve the check.',

    scanEmployeeId: 'Scan Employee ID',
    startCamera: 'Start camera',
    stopCamera: 'Stop camera',
    cameraError: 'Unable to start the camera. Enter the card token below instead.',
    startingCamera: 'Starting camera…',
    cameraIdle: 'Camera is off. Start it to scan a card QR code.',
    cameraPermissionDenied: 'Camera permission was denied. Allow camera access, or enter the card token below.',
    cameraNotFound: 'No camera was found on this device. Enter the card token below instead.',
    cameraInUse: 'The camera is being used by another application. Close it and try again.',
    cameraInsecureOrigin: 'Camera scanning needs a secure (https) connection. Enter the card token below instead.',
    enterCardToken: 'Or enter the card token',
    or: 'or',
    scannerUnavailable: 'The camera scanner could not be loaded. Enter the card token below instead.',
    loadingScanner: 'Loading scanner…',
    scanAgain: 'Scan again',
    scanAnotherCard: 'Check another card',
    toggleTorch: 'Toggle flash',
    verifying: 'Verifying…',
    checkCard: 'Check card',

    cardStatus: 'Card status',
    cardDetected: 'Card detected. Send OTP to verify.',
    cardFoundNoInfo: 'Employee details are shown only after the card holder approves the check.',
    cardNotActive: 'This card cannot be verified.',

    otpExplainer:
        'A one-time code will be sent to the card holder’s registered email and phone. Ask them for the code to continue.',
    sendOtp: 'Send OTP',
    sending: 'Sending…',
    enterOtp: 'Enter OTP',
    verifyOtp: 'Verify OTP',
    resendOtp: 'Resend code',

    otpSent: 'OTP sent to the employee’s registered email and phone',
    verificationSuccessful: 'Verification successful',
    verificationFailed: 'Verification failed',
    cannotVerifyCard: 'This card cannot be verified',
    otpExpired: 'OTP expired',
    tooManyAttempts: 'Too many attempts',
    tooManyRequests: 'Too many requests. Please wait and try again.',

    verifiedDetails: 'Verified employee details',
    fullName: 'Full name',
    employeeNumber: 'Employee number',
    organization: 'Organization',
    organizationUnit: 'Organization unit',
    position: 'Position',
    cardNumber: 'Card number',
    issuedAt: 'Issued date',
    expiresAt: 'Expiry date',
    verifiedAt: 'Verification time',
    privacyNote:
        'Contact details, national ID and other personal data are never shown here.',

    status_active: 'Active',
    status_expired: 'Expired',
    status_revoked: 'Revoked',
    status_lost: 'Reported lost',
    status_replaced: 'Replaced',
    status_inactive: 'Not active',
    status_invalid: 'Not valid',
};
