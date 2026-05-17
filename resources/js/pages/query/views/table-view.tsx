import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslation } from '@/hooks/use-translation';

import {
    bucketForColumn,
    formatBucketLabel,
    formatCell,
    humanizePascalCase,
} from '../format';
import type { Plan, QueryRow } from '../types';

export function TableView({
    rows,
    plan,
    locale,
}: {
    rows: QueryRow[];
    plan: Plan;
    locale: string;
}) {
    const { t } = useTranslation();
    const columns = Object.keys(rows[0] ?? {});

    return (
        <div className="overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        {columns.map((c) => (
                            <TableHead key={c} className="text-xs">
                                {humanizePascalCase(c)}
                            </TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row, i) => (
                        <TableRow key={i}>
                            {columns.map((c) => {
                                const value = row[c];
                                const bucket = bucketForColumn(plan, c);
                                const formatted =
                                    bucket !== null
                                        ? formatBucketLabel(
                                              value,
                                              bucket,
                                              locale,
                                          )
                                        : formatCell(value, locale, t);

                                return (
                                    <TableCell key={c} className="text-xs">
                                        {formatted}
                                    </TableCell>
                                );
                            })}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
