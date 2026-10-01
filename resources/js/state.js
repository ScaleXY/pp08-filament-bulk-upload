export const GRID_SPECS = { '5x5': { columns: 5, size: 25 }, '4x3': { columns: 4, size: 12 }, '7x5': { columns: 7, size: 35 }, '4x4': { columns: 4, size: 16 } }
export const PAGE_SIZE = 25
export function filePage(files, page, size = PAGE_SIZE) { return files.slice((page - 1) * size, page * size) }
export function isBusy(files, registering = false) {
    return registering || files.some(file => !file.meta.verified)
}
export function moveReference(order, reference, offset) {
    const next = [...order], index = next.indexOf(reference), target = index + offset
    if (index >= 0 && target >= 0 && target < next.length) [next[index], next[target]] = [next[target], next[index]]
    return next
}
