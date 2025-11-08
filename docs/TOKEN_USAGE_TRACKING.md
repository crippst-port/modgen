TOKEN USAGE & TRUNCATION DETECTION
===================================

## What's Being Tracked

When the AI generates module content, the system now logs:

1. **Prompt tokens (estimated)** - How many tokens the prompt uses
2. **Response tokens (estimated)** - How many tokens the AI generated
3. **Total tokens** - Sum of both
4. **Response length** - Character count of response
5. **Truncation detection** - Whether response looks incomplete

## How Truncation Detection Works

The system checks for incomplete JSON by counting opening/closing braces:
- If `{` + `[` count > `}` + `]` count, the response is flagged as truncated
- This usually means the AI ran out of tokens mid-generation
- Example: `{"themes": [{"title": "Theme 1"` (missing closing braces)

## Viewing Logs

### Quick View
```bash
cd /Users/tom/Sites/moodle45/ai/placement/modgen/docs
./view_token_usage.sh
```

This shows:
- Last 50 lines of activity
- Total truncation warnings
- Average token usage (last 10 generations)
- Maximum token usage

### Manual View
```bash
tail -50 /tmp/modgen_token_usage.log
tail -f /tmp/modgen_token_usage.log  # Watch in real-time
```

### Clear Log
```bash
rm /tmp/modgen_token_usage.log
```

## Interpreting the Output

**Example log entry:**
```
=== AI Generation Debug ===
Prompt tokens (est): 450
Response tokens (est): 280
Total tokens (est): 730
Response length: 1120 chars
Looks truncated: NO
Response ends with: ...,"summary": "Overview of key concepts"}]}}
---
```

**What to look for:**

| Issue | Sign | Action |
|-------|------|--------|
| **Truncation happening** | "Looks truncated: YES" | Reduce content or split generation |
| **High token usage** | Total > 3000 | May hit limits soon |
| **Incomplete content** | Ends with `[` or `{` | Content was cut off |
| **Normal** | "Looks truncated: NO" + proper JSON | All good |

## Token Estimation

The estimate uses: **1 token ≈ 4 characters**

This is approximate. Actual token counts depend on:
- AI provider (OpenAI, Claude, etc)
- Model used (GPT-3.5, GPT-4, etc)
- Specific tokenization rules

For exact counts, check your AI provider's API logs.

## When Content Is Missing

If users report missing topics (like "only 3 of 5 topics"):

1. Check log for truncation: `./view_token_usage.sh`
2. Look for "Looks truncated: YES" entries
3. If truncated frequently:
   - Reduce document content size (currently 50KB max)
   - Reduce number of activities requested
   - Split large modules into smaller chunks

## Configuration

Current settings in `ai_service.php`:

- Document truncation: **50,000 chars max** per document
- Estimated token rate: **1 token = 4 characters**
- Truncation check: Looks for incomplete JSON braces

To adjust:
1. Edit `ai_service.php` line ~516 (document truncation)
2. Edit `ai_service.php` line ~585 (token ratio estimation)
3. Rebuild and clear caches

## Related Files

- `/tmp/modgen_token_usage.log` - Debug log (created on first generation)
- `classes/local/ai_service.php` - Lines 580-610 (logging code)
- `docs/view_token_usage.sh` - Viewer script
