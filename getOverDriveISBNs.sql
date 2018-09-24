select distinct
    i.value as ISBN
--    , b.overdriveId
    , b.title
    , b.primaryCreatorName
--    , b.deleted
--    , from_unixtime(b.dateDeleted)
--    , a.copiesOwned
--    , a.copiesAvailable
--    , a.numberOfHolds
--    , f.textId
from econtent.overdrive_api_product_identifiers i
left join econtent.overdrive_api_products b on i.productId = b.id
left join econtent.overdrive_api_product_availability a on b.id = a.productId
left join econtent.overdrive_api_product_formats f on b.id = f.productId
where i.type = 'ISBN'
and f.textId like 'audiobook%'
-- we use b.deleted instead of a.copiesOwned 'cause we want to provide access and deleted indicates it ain't findable in Pika.
-- Let them eat Hoopla if we don't point them to OverDrive, even if there's copies really to be had in OverDrive
-- for the record on 2018 09 18 there are 485 titles that are deleted = 1 and copiesOwned > 0
and b.deleted != 1
-- order by rand()
-- limit 10
order by isbn
;
