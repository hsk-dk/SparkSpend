# SparkSpend Design Update - March 4, 2026

## Objective
Update SparkSpend's visual design to match the "family" look of auth.useful.dk and clean.useful.dk applications.

## Key Design Changes

### 1. Typography
**Before**: `font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif`
**After**: `font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Inter', sans-serif`

**Why**: Modern system font stack provides:
- Native fonts on each OS (San Francisco on macOS, Segoe UI on Windows, Inter elsewhere)
- Better rendering and consistency
- Improved readability on all devices
- Professional appearance matching clean.useful.dk

### 2. Color Palette
**Body Text Color**:
- Before: `color: var(--brand-ink)` (#243140)
- After: `color: #1f2937` (slightly darker, more standard)

**Border Color**:
- Before: `border: 1px solid rgba(255, 255, 255, 0.5)` (white)
- After: `border: 1px solid rgba(36, 49, 64, 0.10)` (dark at low opacity)
- Gives subtle definition without visual harshness

### 3. Glassmorphism Refinement
**Background**:
- Before: `background: rgba(255, 255, 255, 0.8)`
- After: `background: rgba(255, 255, 255, 0.86)`

**Blur & Saturation**:
- Before: `backdrop-filter: saturate(150%) blur(14px)` (heavier effect)
- After: `backdrop-filter: saturate(110%) blur(6px)` (cleaner, more refined)

**Why**: The reduced blur and saturation create a cleaner, more premium appearance while maintaining the glassmorphic effect.

### 4. Shadow Enhancement
**Before**: `box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08)` (very subtle)
**After**: `box-shadow: 0 10px 30px rgba(0, 0, 0, 0.20)` (more defined)

**Impact**: 
- Stronger visual depth and card separation
- Better visual hierarchy
- More premium appearance
- Clearer definition of interactive elements

### 5. Background Overlay Gradient
**Before**:
```css
radial-gradient(circle at 20% 80%, rgba(107, 163, 255, 0.05) 0%, transparent 50%),
radial-gradient(circle at 80% 20%, rgba(107, 163, 255, 0.03) 0%, transparent 50%)
```

**After**:
```css
radial-gradient(120% 120% at 50% 30%, rgba(0, 0, 0, 0.00) 0%, rgba(0, 0, 0, 0.05) 55%, rgba(0, 0, 0, 0.14) 100%),
linear-gradient(to bottom, rgba(0, 0, 0, 0.04), rgba(0, 0, 0, 0.06))
```

**Why**: Creates a natural vignette effect from clean.useful.dk, adding depth without visible color tint.

### 6. Form Input Styling
**Before**:
```css
border: 1px solid rgba(107, 163, 255, 0.3);
background: rgba(255, 255, 255, 0.9);
border-radius: 10px;
```

**After**:
```css
border: 1px solid #d1d5db;
background: #ffffff;
border-radius: 8px;
```

**Focus State**:
- Before: `box-shadow: 0 0 0 3px rgba(107, 163, 255, 0.1)` (subtle)
- After: `box-shadow: 0 0 0 3px rgba(107, 163, 255, 0.2)` (more visible)

### 7. Table Styling
**Updated to match card styling**:
- Background: `rgba(255, 255, 255, 0.86)` ✓
- Backdrop filter: `saturate(110%) blur(6px)` ✓
- Shadow: `0 10px 30px rgba(0, 0, 0, 0.15)` ✓
- Border: `1px solid rgba(36, 49, 64, 0.10)` ✓

### 8. Component Updates
All main components updated with new styling:

| Component | Change |
|-----------|--------|
| `#summaryBox` | New glassmorphism + stronger shadow |
| `.card` | New glassmorphism + stronger shadow |
| `.pie-chart-compact` | New glassmorphism + consistent styling |
| `table` | New glassmorphism + stronger shadow |
| Input controls | Cleaner borders, white background |

## Visual Hierarchy Improvements

### Before
- Light blue accent borders created visual noise
- Multiple shade of white/transparent overlaid
- Subtle shadows made cards blend in

### After
- Dark subtle borders with low opacity
- Consistent 86% white background
- Stronger shadows create clear card separation
- Cleaner visual hierarchy with better depth

## Color Consistency

**Maintained Colors**:
- Brand accent blue: `#6BA3FF` (focus states, hover effects)
- Background: `#C9CFD8` (fallback color)
- Dark base: `#243140` (headings, links)

**Improved Color Usage**:
- Better contrast with new text color `#1f2937`
- More subtle borders with opacity
- Shadow colors use neutral black (0,0,0) instead of blue-tinted

## Font Improvements

**System Font Stack Benefits**:
1. **macOS**: Uses San Francisco (native, premium)
2. **Windows**: Uses Segoe UI (native, clean)
3. **Android**: Uses Roboto (native, modern)
4. **Fallback**: Uses Inter (web-safe, modern)

## Cache Busting
**Updated parameter**: `style.css?v=20260304`

This forces browsers to fetch the latest CSS file even if cached.

## Files Modified
- `includes/style.css` - Complete style update
- `index.php` - Cache-bust version parameter

## Expected Visual Result

After clearing browser cache, you should see:

✅ **Cleaner, more modern appearance**
- System fonts render naturally on your device
- Glassmorphic cards look more refined
- Better visual separation between elements

✅ **Matches the Design Family**
- Visual consistency with auth.useful.dk
- Visual consistency with clean.useful.dk
- Professional, premium appearance

✅ **Improved Readability**
- Better contrast with new text color
- Cleaner input styling
- Stronger card definitions

## Browser Cache Clearing Required

**IMPORTANT**: Full cache clear needed to see these changes!

### Chrome/Edge (Windows)
1. `Ctrl+Shift+Delete`
2. Select "Cookies and cached images/files"
3. Time range: "All time"
4. Click "Clear data"
5. Close browser completely
6. Reopen and navigate to site
7. Press `Ctrl+Shift+R`

### Firefox
1. `Ctrl+Shift+Delete`
2. Click "Clear Now"
3. Press `Ctrl+Shift+R`

### Safari
1. Develop menu → Empty Caches
2. Press `Cmd+Shift+R`

## Technical Details

### Backdrop Filter Support
- All modern browsers support `backdrop-filter`
- Fallback colors are included
- No JavaScript needed for effects

### Performance
- Reduced blur amount (6px vs 14px) = slightly better performance
- Reduced saturation (110% vs 150%) = cleaner rendering
- Stronger shadows still efficient

### Accessibility
- Maintained color contrast ratios
- Focus states still clearly visible
- Text remains readable in all contexts

## Before/After Comparison

### Main Content Area
```
Before:
Background: rgba(255, 255, 255, 0.80)
Blur: 14px saturation 150%
Border: rgba(255,255,255, 0.5)
Shadow: 0 8px 32px rgba(0,0,0,0.08)

After:
Background: rgba(255, 255, 255, 0.86)
Blur: 6px saturation 110%
Border: rgba(36,49,64, 0.10)
Shadow: 0 10px 30px rgba(0,0,0,0.20)
```

### Form Inputs
```
Before:
Border: rgba(107,163,255, 0.3) - blue tint
Background: rgba(255,255,255, 0.9) - translucent

After:
Border: #d1d5db - neutral gray
Background: #ffffff - pure white
```

## Design Philosophy

The new design maintains SparkSpend's functionality while adopting the visual language of the useful.dk family:

1. **Clean & Modern**: Reduced visual clutter, clearer hierarchy
2. **Consistent**: Matches other useful.dk applications
3. **Professional**: Stronger shadows and definition
4. **Accessible**: Better contrast and readability
5. **Premium**: Refined glassmorphism effect

---

**Last Updated**: March 4, 2026
**Version**: 20260304
**Status**: Ready for user testing after cache clear
