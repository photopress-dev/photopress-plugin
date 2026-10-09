/**
 * Stands in for @wordpress/components in unit tests (see vitest.config.mjs).
 * It is not installed: the build leaves it to WordPress. The editor controls
 * that use it are not rendered in unit tests.
 */
const Stub = () => null;

export const PanelBody = Stub;
export const RangeControl = Stub;
export const SelectControl = Stub;
export const ToggleControl = Stub;
