import Uppy from '@uppy/core'
import AwsS3 from '@uppy/aws-s3'
import { GRID_SPECS, filePage, isBusy, moveReference } from './state.js'

export default function bulkMediaUpload({ state, config }) {
    // Keep Uppy and Blob objects outside Alpine's reactive state.
    let uppy, poll, refreshTimer, alive = true, starting
    let verifying = 0, mediaRequest = 0
    const previews = new Map()
    const clearPreviews = () => { previews.forEach(url => URL.revokeObjectURL(url)); previews.clear() }
    const verificationQueue = [], pendingVerification = new Map()
    const drainVerification = () => {
        while (verifying < config.concurrency && verificationQueue.length) {
            const task = verificationQueue.shift()
            verifying++
            task.run().finally(() => { verifying--; pendingVerification.delete(task.id); task.resolve(); drainVerification() })
        }
    }
    return {
        state, config, gridSpec: '5x5', mediaLoading: false, rows: [], page: 1, totalPages: 1, totalFiles: 0, progress: 0,
        registering: false, error: '', existing: [], mediaPage: 1, mediaLastPage: 1,
        completedLoaded: false, batch: null, locked: false, ready: false, dragging: false,
        get pageSize() { return GRID_SPECS[this.gridSpec].size },
        get gridColumns() { return GRID_SPECS[this.gridSpec].columns },
        async changeGrid() {
            if (!GRID_SPECS[this.gridSpec]) this.gridSpec = '5x5'
            this.page = 1
            this.refresh()
            await this.loadMedia(1)
        },
        async init() {
            uppy = new Uppy({ autoProceed: false, restrictions: { maxNumberOfFiles: config.maxFiles, maxFileSize: config.maxBytes } })
            const operation = (file, action, body = {}) => this.api(`files/${file.meta.uploadId}/${action}`, body)
            uppy.use(AwsS3, {
                limit: config.concurrency, shouldUseMultipart: file => file.size >= 100 * 1024 * 1024,
                getChunkSize: file => Math.max(8 * 1024 * 1024, Math.ceil(file.size / 10000)),
                getUploadParameters: file => operation(file, 'put'),
                createMultipartUpload: file => operation(file, 'multipart'),
                signPart: (file, { partNumber }) => operation(file, 'part', { partNumber }),
                listParts: async file => (await operation(file, 'parts')).parts,
                abortMultipartUpload: file => operation(file, 'abort').then(() => undefined),
                completeMultipartUpload: (file, { parts }) => operation(file, 'complete', { parts }),
            })
            uppy.on('upload-progress', () => this.scheduleRefresh())
            uppy.on('upload-error', () => this.refresh())
            uppy.on('restriction-failed', (_file, error) => { this.error = error.message })
            uppy.on('upload-success', file => this.verify(file))
            uppy.on('complete', () => this.refresh())
            this.state ??= { session: null, order: [], remove: [], busy: false, selected: 0 }
            try {
                await this.ensureSession()
                await this.loadMedia()
                await this.loadStatus()
                this.ready = true
                poll = setInterval(() => { if (this.ready) this.loadStatus().catch(error => { this.error = error.message }) }, 3000)
            } catch (error) { this.error = error.message }
        },
        async ensureSession() {
            if (this.state.session) return
            if (!starting) starting = (async () => {
                const result = await this.request(config.endpoint, { token: config.token })
                this.state = { session: result.session, order: result.order, remove: result.remove ?? [], busy: false, selected: 0 }
            })()
            try { await starting } finally { starting = null }
        },
        async request(url, body) {
            const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: {
                'Content-Type': 'application/json', 'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || window.livewireScriptConfig?.csrf || document.querySelector('script[data-csrf]')?.dataset.csrf || config.csrfToken,
            }, body: JSON.stringify(body) })
            const data = await response.json().catch(() => ({}))
            if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat()[0] ?? data.message ?? `Request failed (${response.status})`)
            return data
        },
        api(path, body = {}) { return this.request(`${config.endpoint.replace(/\/sessions$/, '')}/${this.state.session}/${path}`, body) },
        async addFiles(input) {
            if (this.locked || config.readonly || this.registering || !this.ready) return
            this.error = ''; this.registering = true; this.updateBusy()
            const added = []
            try {
                for (const file of Array.from(input)) {
                    try { added.push(uppy.addFile({ name: file.name, type: file.type, data: file })) }
                    catch (error) { this.error = error.message }
                }
                if (!added.length) return
                this.updateBusy()
                const result = await this.api('files', { files: added.map(id => { const f = uppy.getFile(id); return { name: f.name, size: f.size } }) })
                result.files.forEach((file, index) => {
                    uppy.setFileMeta(added[index], { uploadId: file.id, verified: false })
                })
                this.state = { ...this.state, order: [...this.state.order, ...result.files.map(file => `upload:${file.id}`)] }
            } catch (error) {
                this.error = error.message
                added.forEach(id => uppy.removeFile(id))
            } finally { this.registering = false; this.refresh() }
        },
        verify(file) {
            if (pendingVerification.has(file.id)) return pendingVerification.get(file.id)
            let resolve
            const pending = new Promise(done => { resolve = done })
            pendingVerification.set(file.id, pending)
            verificationQueue.push({ id: file.id, resolve, run: () => this.performVerification(file) })
            drainVerification()
            return pending
        },
        async performVerification(file) {
            if (!alive || !uppy.getFile(file.id)) return
            try {
                await this.api(`files/${file.meta.uploadId}/verify`)
                if (uppy.getFile(file.id)) uppy.setFileMeta(file.id, { verified: true, verifyError: '' })
            } catch (error) {
                if (uppy.getFile(file.id)) uppy.setFileMeta(file.id, { verifyError: error.message })
                this.error = error.message
            } finally { if (alive) this.refresh() }
        },
        start() { this.error = ''; uppy.upload().catch(error => { this.error = error.message }); this.refresh() },
        retry(id) {
            const file = uppy.getFile(id)
            if (file.progress.uploadComplete) return this.verify(file)
            uppy.retryUpload(id).catch(error => { this.error = error.message })
        },
        pause(id) { uppy.pauseResume(id); this.refresh() },
        async remove(id) {
            if (this.locked || config.readonly) return
            const file = uppy.getFile(id)
            uppy.pauseResume(id)
            try {
                await this.api(`files/${file.meta.uploadId}/cancel`)
                uppy.removeFile(id)
                this.state = { ...this.state, order: this.state.order.filter(ref => ref !== `upload:${file.meta.uploadId}`) }
                this.refresh()
            } catch (error) { this.error = error.message }
        },
        updateBusy() {
            const files = uppy.getFiles(), selected = files.length
            const busy = !this.locked && isBusy(files, this.registering)
            if (this.state.busy !== busy || this.state.selected !== selected) this.state = { ...this.state, busy, selected }
        },
        scheduleRefresh() { if (!refreshTimer) refreshTimer = setTimeout(() => { refreshTimer = null; if (alive) this.refresh() }, 100) },
        refresh() {
            const files = uppy.getFiles()
            this.totalFiles = files.length
            this.totalPages = Math.max(1, Math.ceil(files.length / this.pageSize))
            this.page = Math.min(this.page, this.totalPages)
            const bytes = files.reduce((sum, file) => sum + file.size, 0)
            this.progress = bytes ? Math.round(files.reduce((sum, file) => sum + (file.progress.bytesUploaded ?? 0), 0) / bytes * 100) : 0
            const visible = filePage(files, this.page, this.pageSize)
            const visibleIds = new Set(visible.map(file => file.id))
            for (const [id, url] of previews) {
                if (!visibleIds.has(id)) { URL.revokeObjectURL(url); previews.delete(id) }
            }
            this.rows = visible.map(file => {
                if (file.type?.startsWith('image/') && !previews.has(file.id)) previews.set(file.id, URL.createObjectURL(file.data))
                return {
                    preview: previews.get(file.id), id: file.id, reference: `upload:${file.meta.uploadId}`, name: file.name, size: file.size,
                    progress: file.progress.percentage ?? 0, paused: file.isPaused, multipart: file.size >= 100 * 1024 * 1024,
                    error: file.error || file.meta.verifyError, verified: file.meta.verified,
                    status: file.meta.verified ? 'Uploaded' : file.error || file.meta.verifyError ? 'Failed' : file.isPaused ? 'Paused' : file.progress.uploadComplete ? 'Verifying' : file.progress.uploadStarted ? 'Uploading' : 'Waiting',
                }
            })
            this.updateBusy()
        },
        changePage(offset) { this.page += offset; this.refresh() },
        move(reference, offset) {
            if (this.locked || config.readonly) return
            this.state = { ...this.state, order: moveReference(this.state.order, reference, offset) }
        },
        position(reference) { return this.state.order.indexOf(reference) + 1 },
        removeMedia(id) {
            if (this.locked || config.readonly) return
            this.state = { ...this.state, remove: [...this.state.remove, id], order: this.state.order.filter(ref => ref !== `media:${id}`) }
        },
        restoreMedia(id) {
            this.state = { ...this.state, remove: this.state.remove.filter(value => value !== id), order: [...this.state.order, `media:${id}`] }
        },
        async loadMedia(page = this.mediaPage) {
            const request = ++mediaRequest
            this.mediaLoading = true
            try {
                const result = await this.api('media', { page, per_page: this.pageSize })
                if (!alive || request !== mediaRequest) return
                if (page > result.last_page) return await this.loadMedia(result.last_page)
                this.existing = result.data; this.mediaPage = page; this.mediaLastPage = result.last_page
            } catch (error) { if (request === mediaRequest) this.error = error.message }
            finally { if (request === mediaRequest) this.mediaLoading = false }
        },
        async loadStatus() {
            this.batch = await this.api('status')
            this.locked = !['open', 'expired'].includes(this.batch.status)
            if (this.locked) this.state = { ...this.state, busy: false }
            if (this.batch.status === 'completed' && !this.completedLoaded) { this.completedLoaded = true; await this.loadMedia() }
        },
        async retryBatch() { try { await this.api('retry'); await this.loadStatus() } catch (error) { this.error = error.message } },
        async newBatch(action = 'renew') {
            this.ready = false
            try {
                const result = await this.api(action)
                clearPreviews()
                uppy.cancelAll()
                this.state = { session: result.session, order: result.order, remove: result.remove ?? [], busy: false, selected: 0 }
                this.locked = false; this.batch = null; this.completedLoaded = false
                await this.loadMedia(); this.ready = true; this.refresh()
            } catch (error) { this.error = error.message; this.ready = true }
        },
        formatSize(bytes) { return bytes >= 1e9 ? `${(bytes / 1e9).toFixed(2)} GB` : `${(bytes / 1e6).toFixed(2)} MB` },
        destroy() { alive = false; clearInterval(poll); clearTimeout(refreshTimer); clearPreviews(); uppy?.destroy() },
    }
}
