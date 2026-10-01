export const PAGE_SIZE = 25
export function filePage(files, page) { return files.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE) }
export function isBusy(files, registering = false) {
    return registering || files.some(file => !file.meta.verified)
}
export function moveReference(order, reference, offset) {
    const next = [...order], index = next.indexOf(reference), target = index + offset
    if (index >= 0 && target >= 0 && target < next.length) [next[index], next[target]] = [next[target], next[index]]
    return next
}
