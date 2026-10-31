/**
 * Text the React respondent-portal pages need that no lang/{en,am}/*.php key provides (the Blade response form
 * hard-coded its PDF instruction in Amharic; the Blade delete asked with the bare word "Delete").
 * To be merged into court/i18n/{en,am}.ts under `court.respondentPages`; until then
 * court/i18n/useRespondentText.ts reads it directly as the fallback.
 */
const respondentPages = {
    en: {
        // `:field` is the name of the main-content field, shown in bold.
        response_pdf_note: 'When you respond, scan the response bearing the signature and seal of the party concerned and attach it as a PDF. Also enter the full content of the response in the :field field.',
        delete_response_confirm: 'Delete this response? Its PDF is deleted with it.',
        size_kb: ':size KB',
    },
    am: {
        response_pdf_note: 'መልሱ ሲሰጡ የሚመለከተው አካል ፊርማ እና ማህተም ያረፈበትን መልስ ስካን አድርገው በፒዲፍ ያያይዙ. እንዲሁም የመልሱን ሙሉ ይዘት በወርድ :field የሚለው ላይ ያስገቡ።',
        delete_response_confirm: 'ይህ መልስ ይሰረዝ? የተያያዘው ፒዲኤፍም አብሮ ይሰረዛል።',
        size_kb: ':size ኪባ',
    },
} as const;

export type RespondentPagesTextKey = keyof typeof respondentPages.en;

export default respondentPages;
