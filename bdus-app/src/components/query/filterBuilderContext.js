// Shared by FilterBuilder (provides) and FilterGroupNode (injects): the tree
// being edited and the field / operator lists, kept as refs so a replaced tree
// (reset, a restored URL) reaches every node.
export const FILTER_BUILDER_KEY = Symbol('filterBuilder')
