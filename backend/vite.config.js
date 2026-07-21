import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { compile } from '@vue/compiler-dom';
import ts from 'typescript';

function precompileInlineVueTemplates() {
    return {
        name: 'precompile-inline-vue-templates',
        enforce: 'pre',
        transform(source, id) {
            if (!id.includes('/resources/') || !id.endsWith('.ts')) return null;

            const file = ts.createSourceFile(id, source, ts.ScriptTarget.Latest, true);
            const replacements = [];
            const helperImports = new Set();
            const renderFunctions = [];
            let templateIndex = 0;

            const visit = (node) => {
                if (ts.isPropertyAssignment(node) && node.name.getText(file) === 'template') {
                    const initializer = node.initializer;
                    if (!ts.isStringLiteral(initializer) && !ts.isNoSubstitutionTemplateLiteral(initializer)) {
                        this.error(`Inline Vue template in ${id} must be a string literal so it can be compiled during the build.`);
                    }

                    const template = initializer.text;
                    const renderName = `__rpgaysRender${templateIndex++}`;
                    let compiled;
                    try {
                        compiled = compile(template, { mode: 'module', prefixIdentifiers: true }).code;
                    } catch (error) {
                        const expression = error?.loc?.source;
                        this.error(`Unable to compile inline Vue template ${templateIndex} in ${id}${expression ? ` (${expression})` : ''}: ${error.message}`);
                    }
                    const importMatch = compiled.match(/^import \{([\s\S]+?)\} from "vue"\n\n/);
                    if (importMatch === null) this.error(`Unable to compile inline Vue template in ${id}.`);

                    importMatch[1].split(',').forEach((helper) => helperImports.add(helper.trim()));
                    renderFunctions.push(compiled
                        .slice(importMatch[0].length)
                        .replace('export function render', `function ${renderName}`));
                    replacements.push({
                        start: node.getStart(file),
                        end: node.getEnd(),
                        text: `render: ${renderName}`,
                    });
                }

                ts.forEachChild(node, visit);
            };

            visit(file);
            if (replacements.length === 0) return null;

            let transformed = source;
            replacements.sort((left, right) => right.start - left.start).forEach(({ start, end, text }) => {
                transformed = `${transformed.slice(0, start)}${text}${transformed.slice(end)}`;
            });

            return {
                code: `import { ${[...helperImports].join(', ')} } from 'vue';\n${renderFunctions.join('\n')}\n${transformed}`,
                map: null,
            };
        },
    };
}

export default defineConfig({
    resolve: {
        alias: {
            vue: 'vue/dist/vue.runtime.esm-bundler.js',
        },
    },
    plugins: [
        precompileInlineVueTemplates(),
        laravel({
            input: ['resources/css/app.css', 'resources/control/main.ts', 'resources/presentation/main.ts', 'resources/participant/main.ts'],
            refresh: true,
        }),
        tailwindcss(),
        vue(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
