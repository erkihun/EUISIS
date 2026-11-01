// Text the access forms (users, teams, roles, permissions) need that no lang/*.php key provides.
// Read through useAccessForms() until it is wired into the dictionary as `court.accessForms.*`.
const access = {
    en: {
        removeCurrent: 'Remove the current image',
        noUsersAvailable: 'No users are available to assign.',
        noPermissionsDefined: 'No permissions are defined yet.',
        selectedCount: ':count selected',
    },
    am: {
        removeCurrent: 'አሁን ያለውን ምስል አስወግድ',
        noUsersAvailable: 'ሊመደቡ የሚችሉ ተጠቃሚዎች የሉም።',
        noPermissionsDefined: 'እስካሁን የተገለጸ ፈቃድ የለም።',
        selectedCount: ':count ተመርጠዋል',
    },
} satisfies Record<'en' | 'am', Record<string, string>>;

export type AccessFormsKey = keyof typeof access.en;

export default access;
