/**
 * Text the React letter forms (compose, edit, templates, categories) need that
 * no lang/{en,am}/*.php key provides: the Blade category pages and the template
 * form carried these words in English only.
 * To be merged into court/i18n/{en,am}.ts under `court.letterForms`; until then
 * Pages/Court/Letters/useLetterText.ts reads it directly as the fallback.
 */
const letters = {
    en: {
        categories_title: 'Letter categories',
        categories_description: 'Manage the categories used by letter templates.',
        manage_categories: 'Manage categories',
        back_to_templates: 'Back to templates',
        new_category: 'New category',
        new_category_title: 'New letter category',
        edit_category_title: 'Edit letter category',
        create_category: 'Create category',
        create_category_help: 'Add a category to organize letter templates.',
        edit_category: 'Edit category',
        edit_category_help: 'Update the category details.',
        category_name: 'Category name',
        description: 'Description',
        description_placeholder: 'Optional short description',
        save_category: 'Save category',
        update_category: 'Update category',
        delete_category_confirm: 'Delete this category?',
        no_categories: 'No categories yet.',
        no_categories_create_first: 'No categories yet. Create one first.',
        category_select_or_type: 'Select or type a category',
        header_preview_alt: 'Header preview',
        footer_preview_alt: 'Footer preview',
        placeholder_tags: 'Tags in this template',
        template_loaded: 'Template loaded into the letter.',
        opens_in_new_tab: 'The finished letter opens in a new tab.',
        template_summary: 'Template',
        not_set: 'Not set',
        letter_count: ':count letters',
    },
    am: {
        categories_title: 'የደብዳቤ ምድቦች',
        categories_description: 'የደብዳቤ አብነቶች የሚጠቀሙባቸውን ምድቦች ያስተዳድሩ።',
        manage_categories: 'ምድቦችን አስተዳድር',
        back_to_templates: 'ወደ አብነቶች ተመለስ',
        new_category: 'አዲስ ምድብ',
        new_category_title: 'አዲስ የደብዳቤ ምድብ',
        edit_category_title: 'የደብዳቤ ምድብ አርትዕ',
        create_category: 'ምድብ ፍጠር',
        create_category_help: 'የደብዳቤ አብነቶችን ለማደራጀት ምድብ ያክሉ።',
        edit_category: 'ምድብ አርትዕ',
        edit_category_help: 'የምድቡን ዝርዝሮች ያዘምኑ።',
        category_name: 'የምድብ ስም',
        description: 'መግለጫ',
        description_placeholder: 'አማራጭ አጭር መግለጫ',
        save_category: 'ምድብ አስቀምጥ',
        update_category: 'ምድብ አዘምን',
        delete_category_confirm: 'ይህን ምድብ ይሰረዝ?',
        no_categories: 'እስካሁን ምድብ የለም።',
        no_categories_create_first: 'እስካሁን ምድብ የለም። መጀመሪያ አንድ ይፍጠሩ።',
        category_select_or_type: 'ምድብ ይምረጡ ወይም ይጻፉ',
        header_preview_alt: 'የራስጌ ቅድመ-እይታ',
        footer_preview_alt: 'የግርጌ ቅድመ-እይታ',
        placeholder_tags: 'በዚህ አብነት ውስጥ ያሉ መለያዎች',
        template_loaded: 'አብነቱ ወደ ደብዳቤው ገብቷል።',
        opens_in_new_tab: 'የተጠናቀቀው ደብዳቤ በአዲስ ትር ይከፈታል።',
        template_summary: 'አብነት',
        not_set: 'አልተቀመጠም',
        letter_count: ':count ደብዳቤዎች',
    },
} as const;

export type LetterTextKey = keyof typeof letters.en;

export default letters;
