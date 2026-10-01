import { test, expect } from '@playwright/test'
import { readFile } from 'node:fs/promises'

// Exercise the actual Uppy/Alpine bundle, with storage and signing HTTP boundaries stubbed.
test('1000 files stay paginated and file bytes go only to S3', async ({ page }) => {
    page.on('pageerror', error => console.log('Page error:', error.message))
    page.on('console', message => { if (message.type() === 'error') console.log(message.text()) })
    const requests = [], ids = []
    await page.route('http://upload.test/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname
        const body = request.postDataJSON()
        requests.push({ path, body })
        let result = {}
        if (path === '/sessions') result = { session: 'batch', order: [] }
        if (path.endsWith('/files')) {
            result.files = body.files.map((file, index) => ({ ...file, id: `id-${index}` }))
            ids.push(...result.files.map(file => file.id))
        }
        if (path.endsWith('/media')) result = { data: [], last_page: 1 }
        if (path.endsWith('/status')) result = { status: 'open', counts: {}, failed: [] }
        if (path.endsWith('/put')) result = { method: 'PUT', url: 'http://s3.test/object', headers: {} }
        await route.fulfill({ json: result, headers: { 'Access-Control-Allow-Origin': '*' } })
    })
    let storageWrites = 0
    await page.route('http://s3.test/**', async route => {
        if (route.request().method() === 'PUT') {
            storageWrites++
            expect(route.request().postDataBuffer()).toEqual(Buffer.from('hello'))
        }
        await route.fulfill({ status: 200, headers: { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Methods': 'PUT, OPTIONS', 'Access-Control-Allow-Headers': '*', 'Access-Control-Expose-Headers': 'ETag', 'ETag': '"etag"' }, body: '' })
    })
    await page.setContent(`<meta name="csrf-token" content="test"><div id="field" x-data="bulkMediaUpload({state:{session:null,order:[],remove:[],busy:false},config:{endpoint:'http://upload.test/sessions',token:'token',maxFiles:1000,maxBytes:1000000000,concurrency:4,readonly:false}})">
        <button id="upload" @click="start()">Upload</button>
        <span id="count" x-text="totalFiles"></span><span id="busy" x-text="state.busy"></span>
        <ul><template x-for="row in rows" :key="row.id"><li x-text="row.name"></li></template></ul>
    </div>`)
    const bundle = await readFile('dist/bulk-media-upload.js', 'utf8')
    await page.addScriptTag({ type: 'module', content: `const {default:field} = await import(URL.createObjectURL(new Blob([${JSON.stringify(bundle)}], {type:'text/javascript'}))); window.bulkMediaUpload = field;` })
    await page.waitForFunction(() => !!window.bulkMediaUpload)
    await page.addScriptTag({ path: 'node_modules/alpinejs/dist/cdn.min.js' })
    await page.waitForFunction(() => window.Alpine.$data(document.querySelector('#field')).ready)
    await page.evaluate(async () => {
        const field = window.Alpine.$data(document.querySelector('#field'))
        await field.addFiles(Array.from({length: 1000}, (_, i) => new File(['hello'], `f${i}.txt`, {type:'text/plain'})))
    })
    await expect(page.locator('#count')).toHaveText('1000')
    await expect(page.locator('li')).toHaveCount(25)
    await expect(page.locator('#busy')).toHaveText('true')
    await page.locator('#upload').click()
    try { await expect(page.locator('#busy')).toHaveText('false', { timeout: 60000 }) } catch (error) { console.log('Storage writes:', storageWrites, 'Field:', await page.evaluate(() => { const field = window.Alpine.$data(document.querySelector('#field')); return { error: field.error, rows: field.rows.slice(0, 2) } })); throw error }
    expect(storageWrites).toBe(1000)
    expect(ids).toHaveLength(1000)
    for (const request of requests) {
        expect(JSON.stringify(request.body)).not.toContain('hello')
        expect(JSON.stringify(request.body)).not.toContain('base64')
    }
    const state = await page.evaluate(() => window.Alpine.$data(document.querySelector('#field')).state)
    expect(state.order).toHaveLength(1000)
    expect(JSON.stringify(state).length).toBeLessThan(30000)
    expect(state).not.toHaveProperty('files')
})
