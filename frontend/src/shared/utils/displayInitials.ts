export function displayInitials(name: string): string {
    return (name ?? '')
        .split(' ')
        .slice(0, 2)
        .map((w) => w.charAt(0).toUpperCase())
        .join('')
}
