drop table if exists mackin_data;
create table mackin_data (
    mackinvia_account_id integer
    , yearmonthday integer
    , user_id text
    , count_of_checkouts integer
);
.mode csv
.import "../data/mackin/Nashville daily VIA report_DATEPLACEHOLDERMMDDYYYY.csv" mackin_data
-- Ensure the first row (headers) is not included in the data
delete from mackin_data where rowid = 1;
.headers on
.output "../data/LibraryServices-Checkouts-MackinVIA-student-DATEPLACEHOLDERYYYYMMDD.csv"
select
    ms.tn_school_code
     , md.yearmonthday
     , case when md.user_id like '190______' then md.user_id else coalesce(nullif(i.patronid, ''), md.user_id) end as patronid
     , md.count_of_checkouts
from mackin_data md
    left join mackinvia_school_lookup ms on md.mackinvia_account_id = ms.mackin_school_id
    left join infinitecampus i on lower(md.user_id) = lower(i.emailaddress) and i.emailaddress IS NOT NULL AND i.emailaddress != '';
.output stdout