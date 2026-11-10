const { copyFileSync, mkdirSync, readFileSync, writeFileSync } = require('node:fs');
const { compile } = require('vue-template-compiler');

const template = readFileSync('frontend/chat.html', 'utf8');
const { render, staticRenderFns, errors } = compile(template, { whitespace: 'condense' });

if (errors.length) {
    throw new Error(errors.join('\n'));
}

mkdirSync('assets', { recursive: true });
copyFileSync(require.resolve('vue/dist/vue.runtime.min.js'), 'assets/vue.js');
writeFileSync('assets/template.js', [
    'window.chatTemplate = {',
    `    render: function () { ${render} },`,
    `    staticRenderFns: [${staticRenderFns.map(code => `function () { ${code} }`).join(', ')}]`,
    '};',
    ''
].join('\n'));
