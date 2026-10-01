import { build } from 'esbuild'
import { copyFile } from 'node:fs/promises'
await build({ entryPoints: ['resources/js/bulk-media-upload.js'], outfile: 'dist/bulk-media-upload.js', bundle: true, format: 'esm', target: ['es2022'], minify: true })
await copyFile('resources/css/bulk-media-upload.css', 'dist/bulk-media-upload.css')
