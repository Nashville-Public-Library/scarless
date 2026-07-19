drop table if exists overdrive_data;
create table overdrive_data (
    overdrive_id text
    , user_id text
    , checkouts integer
);

.mode csv
.import "../data/overdrive/OverDrive_Report_DATEPLACEHOLDER.csv" overdrive_data

-- Remove header if it was imported as data
delete from overdrive_data where overdrive_id = 'OverDrive ID' or user_id = 'User ID';

.headers on
.output "../data/LibraryServices-Checkouts-OverDrive-staff-DATEPLACEHOLDERYYYYMMDD.csv"
select
    substr(c.DefaultBranch, -3) as tn_school_code
     , 'DATEPLACEHOLDER_ISO' as yearmonthday
     , c.PatronID as patronid
     , sum(od.checkouts) as count_of_checkouts
from overdrive_data od
join carlx c on od.user_id = c.PatronGUID
group by 1, 2, 3;
.output stdout
.headers off
