.mode csv
.import "../data/mackin_data.csv" mackin_data
.headers on
.output "../data/LibraryServices-Checkouts-MackinVIA-student-"`strftime('%Y-%m-%d', date('now', '-1 day'))`".csv"
select
    ms.tn_school_code
     , md.yearmonthday
     , i.patronid
     , md.count_of_checkouts
from mackin_data md
    left join mackinvia_school_lookup ms on md.mackinvia_account_id = ms.mackin_school_id
    left join infinitecampus i on lower(md.user_id) = lower(i.emailaddress);
.output stdout