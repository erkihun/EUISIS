/**
 * TinyMCE's core, on its own so it runs first.
 *
 * The core file is CommonJS (it assigns module.exports), so the bundler wraps
 * it to run when its importer runs. The model, theme, icons and plugins are
 * plain scripts that read the global `tinymce` the core sets on window. Kept
 * in TinyMceAdapter beside them, the core ran after them (they are evaluated
 * as the adapter's dependencies, the wrapped core in the adapter's own body)
 * and the editor failed with "tinymce is not defined". As a module of its own,
 * imported first, it finishes before the next import starts.
 */
import 'tinymce/tinymce';
