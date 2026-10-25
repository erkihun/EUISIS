import { formatDateDisplay } from '@/lib/calendar/dateFormat';

export type CardEmployee = {
    full_name?: string | null;
    full_name_am?: string | null;
    name_en?: string | null;
    metadata?: { name_en?: string | null; name_am?: string | null } | null;
    gender?: string | null;
    date_of_birth?: string | null;
    nationality?: string | null;
    employment_type?: string | null;
    phone?: string | null;
    email?: string | null;
    address?: string | null;
    photo_url?: string | null;
};

const present = (...values: (string | null | undefined)[]) => values.find((value) => value?.trim());

/** Identity fields shared by screen, print, browser PNG and Save as PDF. */
export function mapCardEmployee(card: { card_number: string; employee?: CardEmployee | null }) {
    const employee = card.employee;
    const birthDate = employee?.date_of_birth?.slice(0, 10);
    // Fixed language and UTC keep the English row independent of UI locale and time zone.
    const englishDate = birthDate
        ? new Date(`${birthDate}T00:00:00Z`).toLocaleDateString('en-GB', {
            day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC',
        })
        : undefined;

    return {
        cardNumber: card.card_number,
        fullName: present(employee?.name_en, employee?.metadata?.name_en, employee?.full_name),
        fullNameAm: present(employee?.full_name_am, employee?.metadata?.name_am, employee?.full_name, employee?.name_en),
        gender: employee?.gender,
        dateOfBirth: englishDate,
        dateOfBirthAm: birthDate ? formatDateDisplay(birthDate, 'ethiopian', 'am') : undefined,
        nationality: employee?.nationality,
        employmentStatus: employee?.employment_type,
        phoneNumber: employee?.phone,
        email: employee?.email,
        address: employee?.address,
        photoUrl: employee?.photo_url,
    };
}
