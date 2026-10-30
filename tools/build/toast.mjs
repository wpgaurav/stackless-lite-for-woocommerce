// Builds assets/js/toast.js: notch-toast and motion bundled, React taken from WordPress's script handles.
// scripts/minify.sh minifies it into toast.min.js like every other asset.
//
// Usage: node tools/build/toast.mjs (run after changing tools/build/toast/entry.js or the
// notch-toast or motion version in package.json, then commit assets/js/toast.js and toast.LICENSE.txt).
import { build } from "esbuild";
import { readFile, writeFile } from "node:fs/promises";

// WordPress registers React 18 as the react, react-dom and react-jsx-runtime handles, which set these globals.
const globals = {
  react: "window.React",
  "react-dom": "window.ReactDOM",
  "react-dom/client": "window.ReactDOM",
  "react/jsx-runtime": "window.ReactJSXRuntime",
};
const wordpressReact = {
  name: "wordpress-react",
  setup(b) {
    b.onResolve({ filter: /^(react|react-dom|react-dom\/client|react\/jsx-runtime)$/ }, (args) => ({ path: args.path, namespace: "wp-global" }));
    b.onLoad({ filter: /.*/, namespace: "wp-global" }, (args) => ({ contents: `module.exports = ${globals[args.path]};`, loader: "js" }));
  },
};

const pkg = JSON.parse(await readFile(new URL("../../package.json", import.meta.url), "utf8"));
const versions = ["notch-toast", "motion"].map((name) => `${name} ${pkg.devDependencies[name]}`).join(", ");

const result = await build({
  entryPoints: [new URL("toast/entry.js", import.meta.url).pathname],
  outfile: new URL("../../assets/js/toast.js", import.meta.url).pathname,
  bundle: true,
  format: "iife",
  target: "es2020",
  platform: "browser",
  define: { "process.env.NODE_ENV": '"production"' },
  legalComments: "inline",
  banner: { js: `/* Stackless toasts. Bundles ${versions} (both MIT). Built by tools/build/toast.mjs; edit tools/build/toast/entry.js. */` },
  plugins: [wordpressReact],
  metafile: true,
  logLevel: "info",
});

// The packager strips comments when it minifies, so the licenses of every bundled package ship
// beside the script instead.
const packages = new Set();
for (const input of Object.keys(result.metafile.inputs)) {
  const match = input.match(/node_modules\/((?:@[^/]+\/)?[^/]+)\//);
  if (match) packages.add(match[1]);
}
let notices = "Third-party code in toast.js, toast.min.js\n";
for (const name of [...packages].sort()) {
  const dir = new URL(`../../node_modules/${name}/`, import.meta.url);
  const meta = JSON.parse(await readFile(new URL("package.json", dir), "utf8"));
  let text = "";
  for (const file of ["LICENSE", "LICENSE.md", "license", "LICENSE.txt"]) {
    try { text = await readFile(new URL(file, dir), "utf8"); break; } catch { /* next */ }
  }
  if (!text) throw new Error(`No license file for ${name}`);
  notices += `\n${"=".repeat(72)}\n${name} ${meta.version} (${meta.license})\n${"=".repeat(72)}\n\n${text.trim()}\n`;
}
await writeFile(new URL("../../assets/js/toast.LICENSE.txt", import.meta.url), notices);
console.log(`Licenses for ${[...packages].sort().join(", ")} written to assets/js/toast.LICENSE.txt`);
