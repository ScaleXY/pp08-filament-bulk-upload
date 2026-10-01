import { test, expect } from '@playwright/test'
import { readFile } from 'node:fs/promises'

// Exercise the actual Uppy/Alpine bundle, with storage and signing HTTP boundaries stubbed.
test('1000 files stay paginated and file bytes go only to S3', async ({ page }) => {
    page.on('pageerror', error => console.log('Page error:', error.message))
    page.on('console', message => { if (message.type() === 'error') console.log(message.text()) })
    const requests = [], ids = []
    await page.route('http://upload.test/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname
        expect(request.headers()['x-csrf-token']).toBe('server-csrf-token')
        const body = request.postDataJSON()
        requests.push({ path, body })
        let result = {}
        if (path === '/sessions') result = { session: 'batch', order: [] }
        if (path.endsWith('/files')) {
            expect(await page.evaluate(() => window.Alpine.$data(document.querySelector('#field')).state.selected)).toBe(body.files.length)
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
    const view = await readFile('resources/views/field.blade.php', 'utf8')
    const markup = view.slice(view.indexOf('<div wire:ignore>'), view.lastIndexOf('    </div>'))
    await page.setContent(`<div id="field" :style="{'--bulk-grid-columns':gridColumns}" x-data="bulkMediaUpload({state:{session:null,order:[],remove:[],busy:false},config:{endpoint:'http://upload.test/sessions',token:'token',csrfToken:'server-csrf-token',maxFiles:1000,maxBytes:1000000000,concurrency:4,readonly:false}})">
        <span id="count" x-text="totalFiles"></span><span id="busy" x-text="state.busy"></span>${markup}</div>`)
    await page.addStyleTag({path:'dist/bulk-media-upload.css'})
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
    for (const [spec, size] of [['4x3',12],['7x5',35],['4x4',16],['5x5',25]]) {
        await page.getByRole('combobox', {name:'Grid layout'}).selectOption(spec)
        await expect(page.locator('ul[aria-label="Selected uploads"] li')).toHaveCount(size)
        await expect.poll(() => requests.filter(request=>request.path.endsWith('/media')).at(-1).body.per_page).toBe(size)
    }
    await page.getByRole('button', {name:'Next',exact:true}).first().click()
    await expect(page.locator('ul[aria-label="Selected uploads"] li').first()).toContainText('f25.txt')
    await page.getByRole('button', {name:'Upload files',exact:true}).click()
    try { await expect(page.locator('#busy')).toHaveText('false', { timeout: 60000 }) } catch (error) { console.log('Storage writes:', storageWrites, 'Field:', await page.evaluate(() => { const field = window.Alpine.$data(document.querySelector('#field')); return { error: field.error, rows: field.rows.slice(0, 2) } })); throw error }
    expect(storageWrites).toBe(1000)
    expect(ids).toHaveLength(1000)
    for (const request of requests) {
        expect(JSON.stringify(request.body)).not.toContain('hello')
        expect(JSON.stringify(request.body)).not.toContain('base64')
    }
    const state = await page.evaluate(() => window.Alpine.$data(document.querySelector('#field')).state)
    expect(state.selected).toBe(1000)
    expect(state.order).toHaveLength(1000)
    expect(JSON.stringify(state).length).toBeLessThan(30000)
    expect(state).not.toHaveProperty('files')
})

test('image grids preview local and saved images and release off-page object URLs', async ({ page }) => {
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=', 'base64')
    await page.route('http://grid.test/**', async route => {
        const path = new URL(route.request().url()).pathname
        if (path === '/preview.png') return route.fulfill({body:png,contentType:'image/png'})
        const body = route.request().postDataJSON()
        let result = {}
        if (path === '/sessions') result = {session:'batch',order:[]}
        if (path.endsWith('/files')) result = {files:body.files.map((file,index)=>({...file,id:`file-${index}`}))}
        if (path.endsWith('/status')) result = {status:'open',counts:{},failed:[]}
        if (path.endsWith('/media')) result = {data:Array.from({length:Math.min(body.per_page,40-(body.page-1)*body.per_page)},(_,index)=>({id:String((body.page-1)*body.per_page+index),name:`Saved ${index}.png`,size:png.length,mime:'image/png',url:'http://grid.test/preview.png'})),last_page:Math.ceil(40/body.per_page)}
        await route.fulfill({json:result,headers:{'Access-Control-Allow-Origin':'*'}})
    })
    const view = await readFile('resources/views/field.blade.php','utf8')
    const markup = view.slice(view.indexOf('<div wire:ignore>'),view.lastIndexOf('    </div>'))
    await page.setContent(`<div id="field" :style="{'--bulk-grid-columns':gridColumns}" x-data="bulkMediaUpload({state:{session:null,order:[],remove:[],busy:false},config:{endpoint:'http://grid.test/sessions',token:'token',csrfToken:'token',maxFiles:1000,maxBytes:1000000000,concurrency:4,readonly:false}})">${markup}</div>`)
    await page.addStyleTag({path:'dist/bulk-media-upload.css'})
    const bundle = await readFile('dist/bulk-media-upload.js','utf8')
    await page.addScriptTag({type:'module',content:`const {default:field}=await import(URL.createObjectURL(new Blob([${JSON.stringify(bundle)}],{type:'text/javascript'}))); window.bulkMediaUpload=field;`})
    await page.waitForFunction(()=>!!window.bulkMediaUpload)
    await page.evaluate(()=>{
        window.previewUrls = new Set()
        const create = URL.createObjectURL.bind(URL), revoke = URL.revokeObjectURL.bind(URL)
        URL.createObjectURL = blob => { const url = create(blob); window.previewUrls.add(url); return url }
        URL.revokeObjectURL = url => { window.previewUrls.delete(url); revoke(url) }
    })
    await page.addScriptTag({path:'node_modules/alpinejs/dist/cdn.min.js'})
    await page.waitForFunction(()=>window.Alpine.$data(document.querySelector('#field')).ready)
    await page.evaluate(async bytes=>{
        await window.Alpine.$data(document.querySelector('#field')).addFiles(Array.from({length:36},(_,index)=>new File([new Uint8Array(bytes)],`Image ${index}.png`,{type:'image/png'})))
    },Array.from(png))
    const uploads = page.locator('ul[aria-label="Selected uploads"]'), media = page.locator('ul[aria-label="Existing media"]')
    await expect(uploads.locator('img')).toHaveCount(25)
    await expect(media.locator('img')).toHaveCount(25)
    await expect.poll(()=>uploads.locator('img').first().evaluate(image=>image.naturalWidth)).toBe(1)
    await expect.poll(()=>media.locator('img').first().evaluate(image=>image.naturalWidth)).toBe(1)
    const firstUrl = await uploads.locator('img').first().getAttribute('src')
    await page.getByRole('navigation',{name:'Upload pages'}).getByRole('button',{name:'Next'}).click()
    await expect(uploads.locator('img')).toHaveCount(11)
    expect(await page.evaluate(url=>window.previewUrls.has(url),firstUrl)).toBe(false)
    await page.getByRole('combobox',{name:'Grid layout'}).selectOption('7x5')
    await expect(uploads.locator('img')).toHaveCount(35)
    await expect(media.locator('img')).toHaveCount(35)
    expect(await page.evaluate(()=>window.previewUrls.size)).toBe(35)
    await page.getByRole('navigation',{name:'Media pages'}).getByRole('button',{name:'Next'}).click()
    await expect(media.locator('img')).toHaveCount(5)
    await page.evaluate(()=>window.Alpine.$data(document.querySelector('#field')).destroy())
    expect(await page.evaluate(()=>window.previewUrls.size)).toBe(0)
})
