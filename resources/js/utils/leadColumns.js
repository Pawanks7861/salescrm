/** Move `from` to the position of `to`. Unknown keys leave the order unchanged. */
export function reorderColumns(order, from, to) {
    if (!from || from === to) return order;
    const next = [...order];
    const fromIndex = next.indexOf(from);
    const toIndex = next.indexOf(to);
    if (fromIndex < 0 || toIndex < 0) return order;
    next.splice(fromIndex, 1);
    next.splice(toIndex, 0, from);
    return next;
}
