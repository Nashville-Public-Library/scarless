# getOverDriveISBNs.sh
# James Staub
# Nashville Public Library
# Composes monthly OverDrive eAudiobook ISBN report and emails them as csv attachments

DATE=`date --date="$(date +%Y-%m-%d)" "+%Y%m%d"`;
HOST=$(cat /etc/hostname)

if [ "$HOST" == "hobvmplapt23.pubnet.metro" ]
	then
		MAILTO="james.staub@nashville.gov RSelhorst@midwesttapes.com"
fi

/opt/rh/php55/root/usr/bin/php getOverDriveISBNs.php;

echo "Nashville to hoopla: Monthly OverDrive eAudiobook report" \
	| mail -s "Nashville to hoopla: Monthly OverDrive eAudiobook report $DATE" \
		-a /home/scarless/data/getOverDriveISBNs.csv \
		-r james.staub@nashville.gov $MAILTO;
