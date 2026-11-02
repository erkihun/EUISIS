// English text for every section and test case, keyed by the same ids as the
// Amharic files (cases.js, cases-structure.js). Case: [title, precondition, steps[], expected[]].
module.exports = {
  SET: {
    title: 'Preparation before registration',
    intro: 'These tests confirm the system is ready to receive institutions before registration starts.',
    cases: {
      'TC-SET-01': ['Organization types exist with their parent rules', 'Signed in as Super Admin or System Admin', [
        'Open the «Organization Types» page.',
        'Confirm the types needed for registration exist (for example bureau, commission, authority, agency, sub-city, woreda).',
        'Review «Allowed Parent Types» for each type.',
      ], [
        'All needed types exist and are active.',
        'The parent-type rules match the official structure (for example a woreda only under a sub-city).',
      ]],
      'TC-SET-02': ['An inactive organization type is not offered on the registration form', 'A temporary organization type created for the test', [
        'Make the temporary type inactive.',
        'Open «New Organization» and look at the «Organization Type» list.',
        'When done, delete the temporary type.',
      ], [
        'The inactive type is not in the list.',
      ]],
      'TC-SET-03': ['The organization code rule is active', 'Signed in as an administrator', [
        'Open the «Code Rules» page.',
        'Confirm the «Organization Code» rule is active and check its format (for example ORG-{YEAR}-{SEQUENCE}).',
        'Open «New Organization» and look at the code field.',
      ], [
        'The rule is active; the form previews the next code.',
        'Note: without an active rule, registration stops with "no active code rule".',
      ]],
      'TC-SET-04': ['A new draft after publication', 'The first version has been published (TC-HIE-04)', [
        'Open «Hierarchy Versions» and confirm the published version exists.',
        'Create a new draft version (draft «A») with a version name and a start date after the published version.',
        'Open the draft\'s list of links.',
      ], [
        'The draft is created and starts as a copy of the published version\'s links (it is not empty).',
      ]],
      'TC-SET-05': ['Test accounts', 'The accounts were created in user administration', [
        'Sign in with two accounts: (a) Super Admin or System Admin, (c) an account that can only view organizations.',
        'Open the «Organizations» page with each account.',
      ], [
        'Both accounts sign in; what each sees matches its permissions.',
        'Note: account (b) — an Organizational Admin assigned to one institution — is created only after that institution is registered and the hierarchy is published (TC-SEC-02).',
      ]],
    },
  },
  ORT: {
    title: 'Organization types',
    intro: 'The types institutions are classified under, and their parent-type rules. They are tested first because they are hard to change once institutions use them.',
    cases: {
      'TC-ORT-01': ['Create a new organization type', 'Account with organization-types permission; the «Organization Type Code» rule is active', [
        'Open «Organization Types» → «Create Organization Type».',
        'Fill in the names (English and Amharic), category, level order and prefix; save.',
      ], [
        'The type is created and gets its code from the rule (for example OT-0001).',
        'The prefix is stored in capital letters.',
      ]],
      'TC-ORT-02': ['Set the allowed parent types', 'At least two types (for example sub-city and woreda)', [
        'Edit the woreda type; under «Allowed Parent Types» choose only sub-city; save.',
        'Open the woreda type again and look at the rule.',
      ], [
        'The rule is saved; sub-city shows as the only allowed parent (checked during registration in TC-HIE-05).',
      ]],
      'TC-ORT-03': ['Changing a type code does not break the rules', 'A type (for example sub-city) that another type (for example woreda) allows as parent', [
        'Change the sub-city type\'s code; save.',
        'Look at the woreda type\'s «Allowed Parent Types».',
      ], [
        'The woreda rule now holds the new code; sub-city is still an allowed parent.',
      ]],
      'TC-ORT-04': ['Delete a type that organizations use', 'A type with at least one registered organization', [
        'Delete the type from the «Organization Types» list.',
      ], [
        'It is not deleted; the message ":count organization(s) use this type. Deactivate the type instead of deleting it." is shown.',
      ]],
      'TC-ORT-05': ['Delete a type another type allows as parent', 'A type with no organizations, listed in another type\'s allowed parents', [
        'Delete the type.',
      ], [
        'It is not deleted; the types that allow it are listed.',
      ]],
      'TC-ORT-06': ['Make a type inactive', 'A temporary type with a «temporary record» registered under it', [
        'Make the type inactive.',
        'Edit an existing organization of that type; try to register a new one.',
      ], [
        'The existing organization keeps the type and can still be edited.',
        'No new organization can be registered with this type.',
      ]],
      'TC-ORT-07': ['Delete and restore an unused type', 'A temporary type with no organizations and not allowed as anyone\'s parent', [
        'Delete the type.',
        'Restore it from the deleted list.',
      ], [
        'It is deleted, then restored; both appear in the audit log.',
      ]],
    },
  },
  HV: {
    title: 'Hierarchy versions',
    intro: 'The parent–child structure of institutions is kept in versions: changes are made in a draft and become official when it is published.',
    cases: {
      'TC-HV-01': ['Create the first (draft) version', 'No published version yet (new system)', [
        'On «Hierarchy Versions», create a new version (version name, start date, source document).',
        'Look at the version\'s status and its list of links.',
      ], [
        'The version is created as a «Draft»; it has no links yet.',
        'Links are added as institutions are registered (TC-HIE-03); the version is published after that (TC-HIE-04).',
      ]],
      'TC-HV-02': ['Duplicate version name', 'At least one version exists', [
        'Create another version with an existing name.',
      ], [
        'An error says the name is already taken.',
      ]],
      'TC-HV-03': ['Edit a draft', 'A draft version', [
        'Change the draft\'s name, dates and notes; save.',
        'Set the end date before the start date; save.',
      ], [
        'The first change is saved.',
        'The second is refused with an error.',
      ]],
      'TC-HV-04': ['Edit a published version', 'A published version', [
        'Try to edit the published version or its links.',
      ], [
        'Not possible; changes are made only in a new draft.',
      ]],
      'TC-HV-05': ['Type rules in the tree editor', 'A draft version; a type with a parent rule (for example woreda only under sub-city)', [
        'In the «Tree Editor», add a link placing the woreda under a bureau.',
      ], [
        'The error "The selected organization type does not allow this parent organization type." is shown.',
      ]],
      'TC-HV-06': ['Publish a stale draft', 'Two drafts (A and B) copied from the same published version', [
        'Publish draft A.',
        'Then try to publish draft B.',
      ], [
        'Draft B is not published; the message "This draft was copied before version "…" was published, so publishing it would undo that version\'s changes. Create a new draft from the current structure." is shown.',
      ]],
      'TC-HV-07': ['Backdated start date', 'A published version (start date for example 2027-01-01) and a draft created after it', [
        'Set the draft\'s start date before the published version\'s date.',
        'Try to publish the draft.',
      ], [
        'The error "The draft must take effect after the current version (…)." is shown.',
      ]],
      'TC-HV-08': ['Archive a draft', 'A draft and a published version', [
        'Archive the draft.',
        'Try to archive the published version.',
      ], [
        'The draft is archived.',
        'The published version cannot be archived directly (only when a new version replacing it is published).',
      ]],
      'TC-HV-09': ['Version history and tree', 'A structure published at least twice', [
        'From «Hierarchy Versions», open the tree of the previous version.',
      ], [
        'The previous version shows as it was; its end date is one day before the new version\'s start.',
      ]],
    },
  },
  REG: {
    title: 'Basic registration',
    intro: 'The full flow of the «New Organization» form: from filling it in to saving and checking the result.',
    cases: {
      'TC-REG-01': ['Required fields', 'Signed in as an administrator', [
        '«Organizations» → click «Create Organization».',
        'Click «Create organization» without filling in any field.',
      ], [
        'Errors show that «Name (English)» and «Name (Amharic)» are required.',
        'No organization is registered.',
      ]],
      'TC-REG-02': ['Register the first (top) institution', 'TC-SET-01 and TC-SET-03 passed; no institution is registered yet', [
        'Take the institution at the top of the structure (for example the city administration); «Parent Organization» stays empty.',
        'Choose the «Organization Type».',
        'Enter both names and both short names.',
        'In «Location», fill in the address; click the map to pick the GPS point; set «Check-in radius (m)» to 100.',
        'In «Official contact», enter email, website, P.O. Box and «Public contact phone» (for example 011 123 4567).',
        'Fill in «Legal Basis Reference» (for example Proclamation No. 94/2019), status «Active» and the start date.',
        'Click «Create organization».',
      ], [
        'The organization is registered; you are taken to its detail page. The other institutions are registered under their parents in TC-HIE-03.',
        'The code was generated by the rule.',
        'Everything entered shows correctly on the detail page; the phone is stored as +251111234567.',
        'Only one success message appears.',
      ]],
      'TC-REG-03': ['Codes are generated in sequence', 'TC-REG-02 passed', [
        'Register two «temporary records» one after the other.',
        'Compare the three codes.',
      ], [
        'The code field cannot be edited by hand (preview only).',
        'The codes are consecutive; none repeats.',
      ]],
      'TC-REG-04': ['Extra spaces in a name', 'Signed in as an administrator', [
        'When registering a «temporary record», enter its name in «Name (English)» with extra spaces before, after and between the words.',
        'Fill in the rest and save.',
      ], [
        'The name is stored with the extra spaces removed (single spaces between words).',
      ]],
      'TC-REG-05': ['A name history record is created', 'TC-REG-02 passed', [
        'Open the registered organization\'s detail page.',
        'Look at «Name History».',
      ], [
        'There is one record; it starts on the organization\'s start date (or the registration date if none was given).',
      ]],
      'TC-REG-06': ['Audit log', 'TC-REG-02 passed', [
        'Open «Audit Logs».',
        'Find the organization-created event.',
      ], [
        'The event is recorded, with the tester\'s account as actor and the registered data.',
      ]],
      'TC-REG-07': ['Logo and branding colour', 'A PNG image under 2 MB', [
        'Upload a logo under «Organization Branding».',
        'Enter «Primary Color» #1d4ed8 (lower case); save.',
      ], [
        'The logo shows on the detail page.',
        'The colour is stored as #1D4ED8 (upper case).',
      ]],
      'TC-REG-08': ['Statuses offered at registration', 'Signed in as an administrator', [
        'Open the «Status» list on «New Organization».',
      ], [
        'Only «Active», «Draft» and «Inactive» are offered. Merged, dissolved and archived are not shown, and the system refuses them if sent another way.',
      ]],
    },
  },
  VAL: {
    title: 'Data validation',
    intro: 'Rules that stop wrong or duplicate data from being registered.',
    cases: {
      'TC-VAL-01': ['Duplicate English name', '«Institution A» is registered and active', [
        'Try to register a «temporary record» with «Institution A»\'s English name, changing the letter case and adding an extra space.',
      ], [
        'The error "An organization with this English name is already registered (code)." appears, naming the existing organization\'s code.',
        'The second organization is not registered.',
      ]],
      'TC-VAL-02': ['Duplicate Amharic name', '«Institution A» is registered and active', [
        'Give a «temporary record» a different English name and try to register it with «Institution A»\'s Amharic name (with an extra space added).',
      ], [
        'The error "An organization with this Amharic name is already registered (code)." appears.',
        'Note: the system does not treat spelling variants (for example ሥ and ስ) as the same; make sure names are taken from the official document.',
      ]],
      'TC-VAL-03': ['Reuse the name of an archived organization', 'A «temporary record» has been registered and archived', [
        'Register a second «temporary record» with the same name.',
      ], [
        'Registration succeeds (an archived, merged or dissolved organization releases its name).',
      ]],
      'TC-VAL-04': ['Duplicate code', '«Institution A» and a «temporary record» are registered', [
        'Open «Edit Organization» for the «temporary record» and change its code to «Institution A»\'s code; save.',
      ], [
        'An error says the code is already taken; the change is not saved.',
      ]],
      'TC-VAL-05': ['Official email and website', 'Signed in as an administrator', [
        'Enter the email "abc@" and save.',
        'Correct the email; enter the website "example.gov.et" (without https://); save.',
      ], [
        'An error is shown for the invalid email.',
        'The website is stored as https://example.gov.et.',
      ]],
      'TC-VAL-06': ['Public contact phone', 'Signed in as an administrator', [
        'Enter "0911-ABC" and save.',
        'Enter "8044" (short code) and save.',
        'Enter "0911 223 344" and save.',
      ], [
        'The first is refused with an error.',
        'The short code is accepted.',
        'The third is stored as +251911223344.',
      ]],
      'TC-VAL-07': ['GPS point', 'Signed in as an administrator', [
        'Fill in only «Latitude», leave «Longitude» empty; save.',
        'Enter latitude 95; save.',
      ], [
        'An error says one cannot be saved without the other.',
        'An error says latitude must be between -90 and 90.',
      ]],
      'TC-VAL-08': ['Check-in radius', 'Signed in as an administrator', [
        'Enter a radius of 10; save.',
        'Enter a radius of 60000; save.',
      ], [
        'Both are refused; the allowed range is 25 to 50,000 metres.',
      ]],
      'TC-VAL-09': ['Validity dates', 'Signed in as an administrator', [
        'Set the end date before the start date; save.',
      ], [
        'An error says the end date must be on or after the start date.',
      ]],
      'TC-VAL-10': ['Logo file type and size', 'A PDF file and an image over 2 MB', [
        'Upload the PDF as the logo; save.',
        'Upload the large image; save.',
      ], [
        'Both are refused; only JPG, PNG or WebP up to 2 MB is allowed.',
      ]],
      'TC-VAL-11': ['Colour code', 'Signed in as an administrator', [
        'Type "blue" in the colour field; save.',
      ], [
        'An error says the colour must be in #RRGGBB format.',
      ]],
      'TC-VAL-12': ['Short name length', 'Signed in as an administrator', [
        'Enter a short name longer than 50 characters; save.',
      ], [
        'An error says the short name may not exceed 50 characters.',
      ]],
    },
  },
  HIE: {
    title: 'Hierarchy placement (parent organization link)',
    intro: 'Linking an organization to its parent. The link is recorded in a draft version; the detail pages show it once that version is published.',
    cases: {
      'TC-HIE-01': ['A draft version is chosen automatically when a parent is selected', 'The first draft version exists (TC-HV-01)', [
        'On «New Organization», choose a «Parent Organization».',
        'Look at the «Hierarchy Version» and «Relationship Type» fields.',
      ], [
        '«Hierarchy Version» fills in with the newest draft; the relationship type defaults to «Reports to».',
        'The selected parent and its hierarchy path are shown.',
      ]],
      'TC-HIE-02': ['No draft version available', 'The first version is published and no new draft has been created yet', [
        'Choose a «Parent Organization».',
        'Fill in the other fields and save.',
      ], [
        'The notice «Draft Hierarchy Version Required» is shown.',
        'Registration does not succeed without a version.',
      ]],
      'TC-HIE-03': ['Register a child organization', 'An active parent organization and a draft version exist', [
        'Choose a parent and register a child organization.',
        'Look at the message and page shown after registration.',
      ], [
        'You are taken to the parent organization\'s page.',
        'The message "Child organization registered. It appears under its parent once hierarchy version "…" is published." is shown.',
        'Until the version is published, the child does not appear under the parent.',
      ]],
      'TC-HIE-04': ['Publish the draft', 'TC-HIE-03 passed', [
        'On «Hierarchy Versions», open the draft and click «Publish».',
        'Open the parent organization\'s page and the hierarchy tree.',
      ], [
        'The version is published; the previous published version is archived.',
        'The child organization appears under its parent.',
      ]],
      'TC-HIE-05': ['Parent type not allowed', 'A type with a parent rule (for example woreda only under sub-city)', [
        'Try to register an organization of type woreda under a bureau.',
      ], [
        'The error "The selected organization type does not allow this parent organization type." is shown.',
      ]],
      'TC-HIE-06': ['Inactive parent organization', 'A «temporary record» made inactive', [
        'Try to choose this «temporary record» as parent.',
      ], [
        'It cannot be selected, or the error "Parent organization must be active." is shown.',
      ]],
      'TC-HIE-07': ['Circular link', 'In a draft version, organization «B» is under organization «A»', [
        'Using «Add Relation» in the draft, add a link placing organization «A» under organization «B».',
      ], [
        'An error says a circular hierarchy is not allowed.',
      ]],
      'TC-HIE-08': ['Duplicate link', 'A draft version with one link', [
        'Add the same parent and child pair again.',
      ], [
        'An error says the link already exists.',
      ]],
      'TC-HIE-09': ['Draft that drops an active organization', 'A published version with an active organization, and a draft copied from it', [
        'In the draft, delete the link of an active organization.',
        'Try to publish the draft.',
      ], [
        'Publishing is refused; the active organizations that would drop out of the structure are named.',
      ]],
      'TC-HIE-10': ['Draft without links', 'The first draft, before any link is added (TC-HV-01)', [
        'Try to publish the draft.',
      ], [
        'The error "A hierarchy version must contain at least one organization edge before publishing." is shown.',
      ]],
    },
  },
  UT: {
    title: 'Organization unit types',
    intro: 'Types of the units inside an institution (for example sector, directorate, team).',
    cases: {
      'TC-UT-01': ['Create a new unit type', 'Account with organization-unit-types permission', [
        'On «Organization Unit Types», create a new type (names in both languages, sort order).',
      ], [
        'The type is created, gets its code from the rule and appears on the unit form.',
      ]],
      'TC-UT-02': ['Duplicate code', 'At least one unit type exists', [
        'Try to create another type with an existing code.',
      ], [
        'An error says the code is taken.',
      ]],
      'TC-UT-03': ['Inactive type for a new unit', 'An inactive unit type', [
        'Try to create a new unit with this type.',
        'Edit an existing unit that already has this type.',
      ], [
        'The new unit is not created: "This unit type is inactive and cannot be used."',
        'The existing unit can be edited without changing its type.',
      ]],
      'TC-UT-04': ['Archive a unit type that units use', 'A unit type used by at least one unit', [
        'Delete (archive) the type.',
      ], [
        'It is not archived; the message ":count unit(s) use this unit type. Change their type before archiving it." is shown.',
      ]],
      'TC-UT-05': ['Archive and restore an unused type', 'A temporary type with no units', [
        'Archive the type, then restore it.',
      ], [
        'Both succeed and appear in the audit log.',
      ]],
    },
  },
  UNIT: {
    title: 'Organization units (internal structure)',
    intro: 'Each institution\'s sectors, directorates and teams. Positions and employee assignments attach to these.',
    cases: {
      'TC-UNIT-01': ['Create the organization\'s main (root) unit', 'A registered organization and an active unit type', [
        '«Organization Units» → open a new unit.',
        'Fill in the organization, «Unit Type» and both names; leave «Parent Unit» empty; save.',
      ], [
        'The unit is created as a root unit in the organization; it gets its code from the rule.',
      ]],
      'TC-UNIT-02': ['Create a sub-unit', 'TC-UNIT-01 passed', [
        'Choose the root unit as «Parent Unit» and create a sub-unit.',
        'Open the organization\'s unit tree.',
      ], [
        'The sub-unit appears under its parent in the tree.',
      ]],
      'TC-UNIT-03': ['Required fields', 'Signed in as an administrator', [
        'Leave the names or the unit type empty and save.',
      ], [
        'Errors point to the required fields; the unit is not created.',
      ]],
      'TC-UNIT-04': ['Duplicate name under one parent', '«Unit A» is registered under a parent unit', [
        'Under the same parent, create another unit with «Unit A»\'s name (with an extra space added).',
        'Under a different parent, create a unit with the same name (remove this temporary unit after the test).',
      ], [
        'The first is refused: "Another unit under the same parent already has this name (…)."',
        'The second is created (different parent).',
      ]],
      'TC-UNIT-05': ['Duplicate code within one organization', 'A unit with a known code', [
        'Try to create another unit in the same organization with that code.',
      ], [
        'An error says the code is taken.',
      ]],
      'TC-UNIT-06': ['Change the parent unit — cycle and self', 'Two units A → B (B is a sub-unit of A)', [
        'Change A\'s «Parent Unit» to B; save.',
        'Change A\'s parent to itself; save.',
      ], [
        'The first is refused: "Circular hierarchy is not allowed."',
        'The second is refused: "A unit cannot be its own parent."',
      ]],
      'TC-UNIT-07': ['Parent unit from another organization', 'Two organizations, each with units', [
        'Try to place a unit of one organization under a unit of the other.',
      ], [
        'An error says the parent unit must belong to the same organization.',
      ]],
      'TC-UNIT-08': ['Move a unit to another organization', 'A registered unit', [
        'Open the edit form and look at the organization field.',
      ], [
        'The organization field is read-only. If sent another way, it is refused: "A unit stays in the organization it was created in."',
      ]],
      'TC-UNIT-09': ['Archive a unit with assigned employees', 'A unit with at least one current assignment (imported in TC-IMP-06)', [
        'Archive the unit.',
      ], [
        'It is not archived; the number of assigned employees is shown with a request to reassign them first.',
      ]],
      'TC-UNIT-10': ['Archive a unit with sub-units or positions', 'A unit with a sub-unit and a unit with a position (imported in TC-IMP-06)', [
        'Try to archive each.',
      ], [
        'Neither is archived; a message asks you to move the sub-units or positions first.',
      ]],
      'TC-UNIT-11': ['Archive and restore an empty unit', 'A temporary unit with no sub-units, positions or assignments', [
        'Archive the unit, then restore it.',
      ], [
        'Both succeed; the unit returns to the tree.',
      ]],
      'TC-UNIT-12': ['Copy structure', 'A source organization with units and a target «temporary record» without units', [
        'Use «Copy Structure» to copy the source\'s units into the target.',
      ], [
        'The units are created in the target with the same tree. If copying positions or functional relationships is selected, those are copied too; employees and assignments are not.',
      ]],
      'TC-UNIT-13': ['Organization scope', 'An account limited to one organization', [
        'Try to create a unit in an organization outside its scope.',
      ], [
        'Not allowed; organizations outside the scope are not offered.',
      ]],
    },
  },
  RL: {
    title: 'Reporting lines',
    intro: 'Whom units report to: structural parent, functional reporting and technical supervision.',
    cases: {
      'TC-RL-01': ['Add a functional reporting line', 'A unit and the organization concerned', [
        'Open the unit\'s «Relationships»; click «Add Relationship».',
        'Choose «Functional Reporting», target type organization, the target and the start date; save.',
      ], [
        'The relationship is recorded and shows on «Reporting Lines» and on the target organization\'s page.',
      ]],
      'TC-RL-02': ['Only one primary structural parent', 'A unit with a primary structural parent', [
        'Add another «Structural Parent» marked «Primary».',
      ], [
        'The error "Only one active primary structural parent is allowed." is shown.',
      ]],
      'TC-RL-03': ['Duplicate active relationship', 'A unit with one active relationship', [
        'Add a relationship with the same type and target again.',
      ], [
        'An error says the relationship already exists.',
      ]],
      'TC-RL-04': ['Reporting to itself', 'A unit', [
        'Add a relationship whose target is the unit itself.',
      ], [
        'The error "A unit cannot report to itself." is shown.',
      ]],
      'TC-RL-05': ['Structural cycle', 'Unit «A» is the «Parent Unit» of unit «B»', [
        'In unit «A»\'s relationships, add a relationship making unit «B» its «Structural Parent».',
      ], [
        'An error says a cycle is not allowed.',
      ]],
      'TC-RL-06': ['Target not allowed for a structural parent', 'A unit', [
        'Add a «Structural Parent» with target type institution office.',
      ], [
        'An error says the target is not allowed for a structural parent.',
      ]],
      'TC-RL-07': ['Relationship dates', 'A unit', [
        'Set the end date before the start date and save.',
      ], [
        'An error says the end date must be on or after the start date.',
      ]],
      'TC-RL-08': ['Edit, delete and restore a relationship', 'An active relationship of a temporary unit', [
        'Change its status to inactive; save.',
        'Delete the relationship, then restore it.',
      ], [
        'Each change is saved and appears in the audit log.',
      ]],
      'TC-RL-09': ['Reporting Lines page', 'An organization with recorded relationships', [
        'Open «Reporting Lines» and choose the organization.',
      ], [
        'The offices and units reporting to the organization are shown, grouped by relationship type.',
      ]],
    },
  },
  EDT: {
    title: 'Editing a registered organization',
    intro: 'Changes after registration keep the history and do not break the structure.',
    cases: {
      'TC-EDT-01': ['Rename an organization', 'A «temporary record» with a name history', [
        'On «Edit Organization», change the English and Amharic names; save.',
        'Look at «Name History» on the detail page.',
      ], [
        'Two records: the previous name ends today; the new name starts today.',
        'The previous name\'s start date does not change.',
      ]],
      'TC-EDT-02': ['Rename to another organization\'s name', 'Two active organizations', [
        'Change the first one\'s name to the second one\'s name; save.',
      ], [
        'A duplicate-name error appears; the change is not saved.',
      ]],
      'TC-EDT-03': ['Edit other data without changing the name', 'A registered organization', [
        'Change only the address, without touching the name; save.',
      ], [
        'The change is saved; no duplicate-name error; no new name history record.',
      ]],
      'TC-EDT-04': ['Change the organization type', 'A «temporary record» with a parent in a draft version; a type that does not fit the parent\'s type', [
        'Change the type to one that does not fit the parent; save.',
        'Change the type to one that fits the parent; save.',
      ], [
        'The first is refused: "The new type does not fit this organization\'s links in the hierarchy (…)".',
        'The second is saved.',
      ]],
      'TC-EDT-05': ['Statuses offered on the edit form', 'An active organization', [
        'Open the «Status» list on «Edit Organization».',
      ], [
        'Only «Active», «Draft», «Inactive» and «Dissolved» are shown. «Merged» is not; archiving is done only with the «Archive» button.',
      ]],
      'TC-EDT-06': ['Edit an archived organization', 'An archived «temporary record»', [
        'Change its «Legal Basis Reference»; save.',
      ], [
        'The change is saved; the status stays «Archived».',
      ]],
      'TC-EDT-07': ['Remove the logo', 'A «temporary record» with a logo', [
        'Remove the logo on the edit form; save.',
      ], [
        'The logo is removed; the change is recorded in the audit log.',
      ]],
    },
  },
  LIF: {
    title: 'Deactivate, archive and delete',
    intro: 'An organization that is in use is never deleted; it is deactivated or archived instead.',
    cases: {
      'TC-LIF-01': ['Delete an unused temporary record', 'A «temporary record» with no links, units or employees', [
        'Open the organization\'s page, choose delete and confirm.',
      ], [
        'The organization is deleted and leaves the list.',
      ]],
      'TC-LIF-02': ['Delete an organization in use', 'An organization linked in the hierarchy or with units', [
        'Open the organization\'s page and look at the delete button.',
      ], [
        'It cannot be deleted; the reasons are listed (for example "Used in published hierarchy"), and «Deactivate instead» or «Archive instead» is suggested.',
      ]],
      'TC-LIF-03': ['Deactivate an organization', 'An active «temporary record»', [
        'Click «Deactivate» and confirm.',
        'When registering a new organization, try to choose this one as parent.',
      ], [
        'The status becomes «Inactive»; it stays in the list.',
        'It cannot be chosen as a parent.',
      ]],
      'TC-LIF-04': ['Archive', 'A «temporary record»', [
        'Click «Archive» and confirm.',
        'Look at the «Organizations» list and «Audit Logs».',
      ], [
        'The organization leaves the main list, but its data is kept.',
        'The archive event appears in the audit log.',
      ]],
    },
  },
  SEC: {
    title: 'Permissions and scope',
    intro: 'Each user registers and sees only within their permissions and scope.',
    cases: {
      'TC-SEC-01': ['View-only user', 'Account (c) from TC-SET-05', [
        'Open the «Organizations» page.',
        'Change the browser address to /organizations/create and open it.',
      ], [
        'The «Create Organization» button is not shown.',
        'The page does not open (403 — forbidden).',
      ]],
      'TC-SEC-02': ['Organizational Admin registering an organization', 'The hierarchy is published (TC-HIE-04)', [
        'Create account (b): an «Organizational Admin» assigned to «Institution A»; sign in with it.',
        'Open «New Organization».',
        'Look at the «Parent Organization» options.',
      ], [
        'The assigned organization is preselected; the «No parent (root)» option is not shown.',
        'Only parents within the account\'s scope are offered.',
      ]],
      'TC-SEC-03': ['Parent outside the scope', 'Account (b) from TC-SET-05', [
        'Search for an organization outside the scope.',
      ], [
        'It is not found or cannot be chosen; if sent anyway, the error "Organizational Admin can create organizations only under their assigned organization." appears.',
      ]],
      'TC-SEC-04': ['Open another organization\'s page directly', 'Account (b) from TC-SET-05; the address of an organization outside its scope', [
        'Open that organization\'s page address in the browser.',
      ], [
        'The page does not open (403).',
      ]],
    },
  },
  IMP: {
    title: 'Importing a structure from Excel («Import Structure»)',
    intro: 'Importing an organization with its units, positions and employees from one file. Nothing is written until «Confirm» is clicked.',
    cases: {
      'TC-IMP-01': ['Download the template', 'Account with organizations.import permission', [
        'Open «Import Structure» and download the template.',
      ], [
        'The file has the sheets Organization, Organization Units, Positions and Employees.',
      ]],
      'TC-IMP-02': ['Preview a valid file', 'A correctly filled file', [
        'Upload the file and review the preview.',
        'Without confirming, look at the «Organizations» list.',
      ], [
        'The preview shows the organization, units, positions and codes to be created.',
        'Nothing is written until confirmation.',
      ]],
      'TC-IMP-03': ['Duplicate organization name in the file', 'A file whose Organization sheet uses the name of an existing active organization', [
        'Upload the file.',
      ], [
        'The error "The name "…" is already registered to organization "…"." appears; confirmation is not possible.',
      ]],
      'TC-IMP-04': ['New organization without an Amharic name', 'A file with organization_name_am empty', [
        'Upload the file.',
      ], [
        'The error "Organization name (Amharic) is required for a new organization." appears.',
      ]],
      'TC-IMP-05': ['Parent organization code in the file', 'A file with parent_organization_code filled in', [
        'Upload the file and read the warnings.',
        'Confirm; then add the link in a draft version and publish it.',
      ], [
        'A warning says the import does not create the link.',
        'The link appears only after the draft is published.',
      ]],
      'TC-IMP-06': ['Confirm the import', 'TC-IMP-02 passed', [
        'Click «Confirm».',
        'Look at the created organization\'s page, «Name History» and «Audit Logs».',
      ], [
        'The organization is created with the code shown in the preview.',
        'There is one name history record; the created event is in the audit log.',
      ]],
      'TC-IMP-07': ['Organizational Admin importing a new organization', 'An Organizational Admin with organizations.import permission', [
        'Upload a file with a new organization code.',
      ], [
        'An error says a new organization can be registered only with the form (under their own organization).',
      ]],
      'TC-IMP-08': ['Existing organization code in the file', 'A file with an existing organization\'s code', [
        'Upload the file.',
      ], [
        'A warning says the organization will be updated, not created; an error appears if it is outside the scope.',
      ]],
      'TC-IMP-09': ['Invalid status or type code', 'A file with status "xyz" or a non-existent organization_type_code', [
        'Upload the file.',
      ], [
        'Each error is shown with its sheet and row number.',
      ]],
    },
  },
  DQ: {
    title: 'Data check after the real registration',
    intro: 'A final check once the official registration is complete, before employees and assignments are linked.',
    cases: {
      'TC-DQ-01': ['Compare with the official list', 'The official list of institutions (for example the executive organs listed in Article 11 of Proclamation No. 94/2019)', [
        'Compare the «Organizations» list with the official list one by one.',
      ], [
        'Each institution is registered exactly once; none is missing or duplicated.',
      ]],
      'TC-DQ-02': ['Names and types', 'TC-DQ-01 passed', [
        'For each organization, compare both names, the short name and the type with the legal document.',
      ], [
        'The spelling matches the legal document; the type is correct.',
      ]],
      'TC-DQ-03': ['Hierarchy completeness', 'A published version', [
        'Open the hierarchy tree.',
        'Look for any organization without a parent other than the city administration (root).',
      ], [
        'Every organization sits under its correct parent; none is without a parent.',
      ]],
      'TC-DQ-04': ['Organogram', 'A published version', [
        'Open a bureau\'s organogram and download the PDF.',
      ], [
        'The chart matches the official structure; the PDF prints Amharic characters correctly.',
      ]],
      'TC-DQ-05': ['Search and filters', 'Registered organizations', [
        'Search the list by code, English name and Amharic name.',
        'Filter by type and status.',
      ], [
        'The results are correct; the summary counts (total, active, inactive) match the list.',
      ]],
      'TC-DQ-06': ['Clean up temporary records', 'Testing is complete', [
        'List the temporary records (organizations, types, units, draft versions) created during testing.',
        'Delete the unused ones; archive the rest.',
      ], [
        'No temporary record remains in the official list.',
      ]],
      'TC-DQ-07': ['Units of each organization', 'The official structure document (BPR / organizational design)', [
        'For each organization, compare the unit tree with the official structure.',
      ], [
        'All sectors, directorates and teams sit under the correct parent; none is duplicated or missing.',
      ]],
      'TC-DQ-08': ['Reporting lines completeness', 'TC-DQ-07 passed', [
        'Confirm the reporting lines each unit needs are recorded.',
      ], [
        'Functional and technical reporting lines match the organizational design.',
      ]],
    },
  },
};
