#!/bin/bash
# View token usage and truncation detection log

echo "=== AI Token Usage & Truncation Detection Log ==="
echo ""

if [ ! -f /tmp/modgen_token_usage.log ]; then
    echo "No log file found. Run module generation first to create: /tmp/modgen_token_usage.log"
    exit 1
fi

# Show last 50 lines (recent generations)
echo "Last 50 lines of log:"
tail -50 /tmp/modgen_token_usage.log

echo ""
echo "=== Summary ==="

# Count truncation warnings
truncation_count=$(grep -c "WARNING: Response appears truncated" /tmp/modgen_token_usage.log 2>/dev/null || echo 0)
echo "Total truncation warnings: $truncation_count"

# Show average tokens
echo ""
echo "Token usage stats (last 10 generations):"
grep "Total tokens" /tmp/modgen_token_usage.log | tail -10 | awk '{print $NF}' | awk '{sum+=$1; count++} END {if (count > 0) print "Average tokens: " int(sum/count)}'

# Show max tokens
echo ""
echo "Highest token usage:"
grep "Total tokens" /tmp/modgen_token_usage.log | tail -10 | awk '{print $NF}' | sort -n | tail -1 | xargs echo "Max tokens:"

echo ""
echo "To clear log: rm /tmp/modgen_token_usage.log"
