jest.mock('twin.macro', () => ({ theme: (value: string) => value }));

import { clearChartData, getEmptyData, getOptions, pushChartData } from '@/components/server/console/chart';

describe('@/components/server/console/chart.ts', function () {
    it('preserves the default chart options while applying nested overrides', function () {
        const callback = (value: string | number) => `${value}%`;
        const options = getOptions({
            scales: {
                y: {
                    suggestedMax: 100,
                    ticks: { callback },
                },
            },
        });
        const yScale = options.scales?.y as { suggestedMax?: number; grid?: { display?: boolean } } | undefined;

        expect(options.responsive).toBe(true);
        expect(options.scales?.x?.min).toBe(0);
        expect(options.scales?.x?.max).toBe(19);
        expect(options.scales?.y?.min).toBe(0);
        expect(yScale?.suggestedMax).toBe(100);
        expect(yScale?.grid?.display).toBe(true);
        expect(options.scales?.y?.ticks?.display).toBe(true);
        expect(options.scales?.y?.ticks?.callback).toBe(callback);
    });

    it('pushes, rounds, clears, and preserves null values for a single dataset', function () {
        const initial = getEmptyData('CPU');
        const pushed = pushChartData(initial, 12.3456);
        const withNull = pushChartData(pushed, null);
        const cleared = clearChartData(withNull);

        expect(pushed.datasets[0].data.slice(-1)[0]).toBe(12.35);
        expect(withNull.datasets[0].data.slice(-1)[0]).toBeNull();
        expect(cleared.datasets[0].data).toEqual(Array(20).fill(-5));
    });

    it('updates both network datasets without merging their arrays together', function () {
        const initial = getEmptyData('Network', 2, (dataset, index) => ({
            ...dataset,
            label: index === 0 ? 'Network In' : 'Network Out',
        }));
        const pushed = pushChartData(initial, [7.777, 8.888]);

        expect(pushed.datasets).toHaveLength(2);
        expect(pushed.datasets[0].label).toBe('Network In');
        expect(pushed.datasets[0].data.slice(-1)[0]).toBe(7.78);
        expect(pushed.datasets[1].label).toBe('Network Out');
        expect(pushed.datasets[1].data.slice(-1)[0]).toBe(8.89);
    });
});
